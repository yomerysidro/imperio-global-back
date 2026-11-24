<?php

namespace App\Services\Core;

use App\Models\ActivationRule;
use App\Models\ManualReactivation;
use App\Models\PaymentLog;
use App\Models\PaymentProductOrder;
use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ActivationService
{
    private static array $activeCache = [];

    public function isActive(User $user): bool
    {
        if ($user->is_admin || app(CorporateRootService::class)->isRootCode($user->uuid)) return true;
        if (ManualReactivation::where('user_id', $user->id)
            ->where('period', now()->startOfMonth()->toDateString())
            ->where('state', ManualReactivation::ACTIVE)->exists()) return true;
        $now = Carbon::now('America/Lima');
        if ($this->isActiveForPeriod($user, $now->copy()->startOfMonth(), $now->copy()->endOfMonth(), false)) return true;

        if ($this->isMonthlyGracePeriod($now)) {
            $previous = $now->copy()->subMonthNoOverflow();
            return $this->isActiveForPeriod($user, $previous->copy()->startOfMonth(), $previous->copy()->endOfMonth(), true);
        }

        return false;
    }

    /** Evaluate the standard activation rule for a page or branch in batches. */
    public function activityForUsers(iterable $users): array
    {
        $activity = [];
        $pending = [];
        $root = app(CorporateRootService::class);
        foreach ($users as $user) {
            $activity[$user->id] = $user->is_admin || $root->isRootCode($user->uuid);
            if (!$activity[$user->id]) {
                $pending[$user->id] = $user->id;
            }
        }
        if (!$pending) {
            return $activity;
        }

        $manualPeriod = now()->startOfMonth()->toDateString();
        $now = Carbon::now('America/Lima');
        $rules = ActivationRule::where('state', true)->get();
        foreach (array_chunk($pending, 500) as $ids) {
            $manual = ManualReactivation::whereIn('user_id', $ids)->where('period', $manualPeriod)
                ->where('state', ManualReactivation::ACTIVE)->pluck('user_id')->all();
            $current = $this->qualifiedUsers(array_diff($ids, $manual), $now, false, $rules);
            $active = [...$manual, ...$current];
            if ($this->isMonthlyGracePeriod($now)) {
                $active = [...$active, ...$this->qualifiedUsers(
                    array_diff($ids, $active), $now->copy()->subMonthNoOverflow(), true, $rules
                )];
            }
            foreach ($active as $id) {
                $activity[$id] = true;
            }
        }
        return $activity;
    }

    private function qualifiedUsers(array $ids, CarbonInterface $month, bool $includeClosed, $rules): array
    {
        if (!$ids || $rules->isEmpty()) {
            return [];
        }

        $period = [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
        $logStates = $includeClosed ? [PaymentLog::PAGADO, PaymentLog::TERMINADO] : [PaymentLog::PAGADO];
        $orderStates = $includeClosed
            ? [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::TERMINADO]
            : [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO];
        $prefix = DB::getTablePrefix();
        $service = DB::table('payment_logs as logs')->join('payment_orders as orders', 'orders.id', '=', 'logs.payment_order_id')
            ->join('packs', 'packs.id', '=', 'orders.pack_id')->whereIn('logs.user_id', $ids)
            ->whereIn('logs.state', $logStates)->whereBetween('logs.created_at', $period)
            ->select('logs.user_id')
            ->selectRaw("SUM({$prefix}packs.points) points, SUM(CASE WHEN {$prefix}orders.amount > 0 THEN {$prefix}orders.amount ELSE {$prefix}packs.price END) amount")
            ->groupBy('logs.user_id')->get()->keyBy('user_id');
        $orders = DB::table('payment_product_orders as orders')->whereIn('orders.user_id', $ids)
            ->whereIn('orders.state', $orderStates)->whereBetween('orders.created_at', $period);
        $product = (clone $orders)->select('orders.user_id')
            ->selectRaw("SUM({$prefix}orders.points) points, SUM({$prefix}orders.amount) amount")
            ->groupBy('orders.user_id')->get()->keyBy('user_id');
        $counts = $orders->join('payment_product_order_details as details', 'details.payment_product_order_id', '=', 'orders.id')
            ->select('orders.user_id')->selectRaw("SUM({$prefix}details.quantity) products")
            ->groupBy('orders.user_id')->pluck('products', 'user_id');

        return array_values(array_filter($ids, fn ($id) => $this->meetsActivationRules(
            $rules, $service->get($id), $product->get($id), (int) $counts->get($id, 0)
        )));
    }

    public function isActiveForCategory(User $user, string $category): bool
    {
        $now = Carbon::now('America/Lima');
        if ($this->isActiveForCategoryPeriod(
            $user, $category, $now->copy()->startOfMonth(), $now->copy()->endOfMonth(), false
        )) return true;

        if ($this->isMonthlyGracePeriod($now)) {
            $previous = $now->copy()->subMonthNoOverflow();
            return $this->isActiveForCategoryPeriod(
                $user, $category, $previous->copy()->startOfMonth(), $previous->copy()->endOfMonth(), true
            );
        }

        return false;
    }

    public function isMonthlyGracePeriod(?CarbonInterface $date = null): bool
    {
        $now = $date ? Carbon::instance($date)->timezone('America/Lima') : Carbon::now('America/Lima');
        return $now->lt($now->copy()->startOfMonth()->addDays(2)->setTime(1, 0));
    }

    public function visiblePeriod(?CarbonInterface $date = null): array
    {
        $now = $date ? Carbon::instance($date)->timezone('America/Lima') : Carbon::now('America/Lima');
        $period = $this->isMonthlyGracePeriod($now) ? $now->copy()->subMonthNoOverflow() : $now;
        return [$period->copy()->startOfMonth(), $period->copy()->endOfMonth()];
    }

    /** La modalidad de mayor puntaje es el requisito mensual de rango. */
    public function hasRankActivity(User $user, ?bool $isActive = null): bool
    {
        if ($user->is_admin || app(CorporateRootService::class)->isRootCode($user->uuid)) return true;
        if (!($isActive ?? $this->isActive($user))) return false;

        $rule = ActivationRule::where('state', true)->orderByDesc('minimum_points')->first();
        if (!$rule) return false;
        [$from, $to] = $this->visiblePeriod();
        $closed = $this->isMonthlyGracePeriod();
        $logStates = $closed ? [PaymentLog::PAGADO, PaymentLog::TERMINADO] : [PaymentLog::PAGADO];
        $orderStates = $closed
            ? [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::TERMINADO]
            : [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO];
        $prefix = DB::getTablePrefix();

        $packs = DB::table('payment_logs as logs')
            ->join('payment_orders as orders', 'orders.id', '=', 'logs.payment_order_id')
            ->join('packs', 'packs.id', '=', 'orders.pack_id')
            ->where('logs.user_id', $user->id)->whereIn('logs.state', $logStates)
            ->whereBetween('logs.created_at', [$from, $to])
            ->selectRaw("{$prefix}packs.category, SUM({$prefix}packs.points) points, SUM(CASE WHEN {$prefix}orders.amount > 0 THEN {$prefix}orders.amount ELSE {$prefix}packs.price END) amount")
            ->groupBy('packs.category')->get();
        $packsByCategory = $packs->keyBy(fn ($pack) => strtolower((string) $pack->category));

        $orders = PaymentProductOrder::with('pack')->where('user_id', $user->id)
            ->whereIn('state', $orderStates)->whereBetween('created_at', [$from, $to])
            ->get()->groupBy(fn ($order) => strtolower((string) ($order->pack?->category ?? '')));
        $productOrders = $orders->get('producto', collect());
        $productPack = $packsByCategory->get('producto');
        $productCount = (int) DB::table('payment_product_order_details as details')
            ->join('payment_product_orders as orders', 'orders.id', '=', 'details.payment_product_order_id')
            ->join('packs', 'packs.id', '=', 'orders.pack_id')
            ->where('orders.user_id', $user->id)->whereIn('orders.state', $orderStates)
            ->whereBetween('orders.created_at', [$from, $to])
            ->whereRaw("LOWER({$prefix}packs.category) = ?", ['producto'])->sum('details.quantity');
        $productPackQualifies = (float) ($productPack->points ?? 0) >= $rule->minimum_points
            && (float) ($productPack->amount ?? 0) >= $rule->minimum_amount;
        $productQualifies = ((float) ($productPack->points ?? 0) + $productOrders->sum('points'))
                >= $rule->minimum_points
            && ((float) ($productPack->amount ?? 0) + $productOrders->sum('amount'))
                >= $rule->minimum_amount
            && ($productPackQualifies || $productCount >= $rule->minimum_products);
        $serviceOrders = $orders->get('servicio', collect());
        $servicePack = $packsByCategory->get('servicio');
        $serviceQualifies = ((float) ($servicePack->points ?? 0) + $serviceOrders->sum('points'))
                >= $rule->minimum_points
            && ((float) ($servicePack->amount ?? 0) + $serviceOrders->sum('amount'))
                >= $rule->minimum_amount;

        return $productQualifies || $serviceQualifies;
    }

    public function isActiveForCategoryPeriod(
        User $user,
        string $category,
        CarbonInterface $from,
        CarbonInterface $to,
        bool $includeClosed = true
    ): bool {
        if ($user->is_admin || app(CorporateRootService::class)->isRootCode($user->uuid)) return true;

        $category = strtolower(trim($category));
        $manual = ManualReactivation::where('user_id', $user->id)
            ->where('category', $category)
            ->where('period', $from->copy()->startOfMonth()->toDateString())
            ->whereIn('state', $includeClosed
                ? [ManualReactivation::ACTIVE, ManualReactivation::EXPIRED]
                : [ManualReactivation::ACTIVE])
            ->exists();

        if ($manual) return true;

        $rules = ActivationRule::where('state', true)->get();
        if ($rules->isEmpty()) return false;
        $prefix = DB::getTablePrefix();
        $serviceStates = $includeClosed ? [PaymentLog::PAGADO, PaymentLog::TERMINADO] : [PaymentLog::PAGADO];
        $productStates = $includeClosed
            ? [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::TERMINADO]
            : [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO];
        if ($category === 'service') {
            $totals = DB::table('payment_logs as logs')
                ->join('payment_orders as orders', 'orders.id', '=', 'logs.payment_order_id')
                ->join('packs', 'packs.id', '=', 'orders.pack_id')
                ->where('logs.user_id', $user->id)->where('packs.category', 'Servicio')
                ->whereIn('logs.state', $serviceStates)
                ->whereBetween('logs.created_at', [$from, $to])
                ->selectRaw("COALESCE(SUM({$prefix}packs.points), 0) points, COALESCE(SUM(CASE WHEN {$prefix}orders.amount > 0 THEN {$prefix}orders.amount ELSE {$prefix}packs.price END), 0) amount")
                ->first();
            return $rules->contains(fn (ActivationRule $rule) =>
                (float) ($totals->points ?? 0) >= $rule->minimum_points
                && (float) ($totals->amount ?? 0) >= $rule->minimum_amount);
        }

        if ($category !== 'product') return false;
        // Los packs iniciales de producto se registran en payment_logs. Esa
        // compra activa por puntos e importe del pack y no requiere detalles
        // de productos, que solo aplican a compras mensuales posteriores.
        $packTotals = DB::table('payment_logs as logs')
            ->join('payment_orders as orders', 'orders.id', '=', 'logs.payment_order_id')
            ->join('packs', 'packs.id', '=', 'orders.pack_id')
            ->where('logs.user_id', $user->id)->where('packs.category', 'Producto')
            ->whereIn('logs.state', $serviceStates)
            ->whereBetween('logs.created_at', [$from, $to])
            ->selectRaw("COALESCE(SUM({$prefix}packs.points), 0) points, COALESCE(SUM(CASE WHEN {$prefix}orders.amount > 0 THEN {$prefix}orders.amount ELSE {$prefix}packs.price END), 0) amount")
            ->first();
        if ($rules->contains(fn (ActivationRule $rule) =>
            (float) ($packTotals->points ?? 0) >= $rule->minimum_points
            && (float) ($packTotals->amount ?? 0) >= $rule->minimum_amount)) {
            return true;
        }

        $totals = DB::table('payment_product_orders as orders')
            ->join('packs', 'packs.id', '=', 'orders.pack_id')
            ->where('orders.user_id', $user->id)->where('packs.category', 'Producto')
            ->whereIn('orders.state', $productStates)
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw("COALESCE(SUM({$prefix}orders.points), 0) points, COALESCE(SUM({$prefix}orders.amount), 0) amount")
            ->first();
        $products = DB::table('payment_product_order_details as details')
            ->join('payment_product_orders as orders', 'orders.id', '=', 'details.payment_product_order_id')
            ->join('packs', 'packs.id', '=', 'orders.pack_id')
            ->where('orders.user_id', $user->id)->where('packs.category', 'Producto')
            ->whereIn('orders.state', $productStates)
            ->whereBetween('orders.created_at', [$from, $to])
            ->sum('details.quantity');
        return $rules->contains(fn (ActivationRule $rule) =>
            (float) ($totals->points ?? 0) >= $rule->minimum_points
            && (float) ($totals->amount ?? 0) >= $rule->minimum_amount
            && (int) $products >= $rule->minimum_products);
    }

    public function isActiveForPeriod(User $user, CarbonInterface $from, CarbonInterface $to, bool $includeClosed = true): bool
    {
        if ($user->is_admin || app(CorporateRootService::class)->isRootCode($user->uuid)) return true;

        $cacheKey = $user->id.':'.$from->format('Y-m').':'.($includeClosed ? 'closed' : 'open');
        if (array_key_exists($cacheKey, self::$activeCache)) return self::$activeCache[$cacheKey];

        $rules = ActivationRule::where('state', true)->get();
        if ($rules->isEmpty()) return false;
        $prefix = DB::getTablePrefix();
        $serviceStates = $includeClosed ? [PaymentLog::PAGADO, PaymentLog::TERMINADO] : [PaymentLog::PAGADO];
        $productStates = $includeClosed
            ? [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::TERMINADO]
            : [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO];
        $service = DB::table('payment_logs as logs')->join('payment_orders as orders', 'orders.id', '=', 'logs.payment_order_id')
            ->join('packs', 'packs.id', '=', 'orders.pack_id')->where('logs.user_id', $user->id)
            ->whereIn('logs.state', $serviceStates)->whereBetween('logs.created_at', [$from, $to])
            ->selectRaw("COALESCE(SUM({$prefix}packs.points), 0) points, COALESCE(SUM(CASE WHEN {$prefix}orders.amount > 0 THEN {$prefix}orders.amount ELSE {$prefix}packs.price END), 0) amount")->first();
        $product = DB::table('payment_product_orders as orders')
            ->where('orders.user_id', $user->id)
            ->whereIn('orders.state', $productStates)
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw("COALESCE(SUM({$prefix}orders.points), 0) points, COALESCE(SUM({$prefix}orders.amount), 0) amount")->first();
        $productCount = DB::table('payment_product_order_details as details')
            ->join('payment_product_orders as orders', 'orders.id', '=', 'details.payment_product_order_id')
            ->where('orders.user_id', $user->id)
            ->whereIn('orders.state', $productStates)
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw("COALESCE(SUM({$prefix}details.quantity), 0) products")->first();
        return self::$activeCache[$cacheKey] = $this->meetsActivationRules(
            $rules, $service, $product, (int) ($productCount->products ?? 0)
        );
    }

    private function meetsActivationRules($rules, ?object $service, ?object $product, int $products): bool
    {
        $servicePoints = (float) ($service->points ?? 0);
        $serviceAmount = (float) ($service->amount ?? 0);
        $productPoints = (float) ($product->points ?? 0);
        $productAmount = (float) ($product->amount ?? 0);

        return $rules->contains(function (ActivationRule $rule) use (
            $servicePoints, $serviceAmount, $productPoints, $productAmount, $products
        ) {
            // Un paquete activa por sus puntos y valor configurado. Una compra
            // mensual de productos debe cumplir ademas la cantidad requerida.
            $serviceQualifies = $servicePoints >= $rule->minimum_points
                && $serviceAmount >= $rule->minimum_amount;
            $productQualifies = $productPoints >= $rule->minimum_points
                && $productAmount >= $rule->minimum_amount
                && $products >= $rule->minimum_products;

            return $serviceQualifies || $productQualifies;
        });
    }

    public static function clearCache(): void { self::$activeCache = []; }
}
