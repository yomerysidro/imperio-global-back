<?php

namespace App\Services\Core;

use App\Models\ActivationRule;
use App\Models\ManualReactivation;
use App\Models\PaymentLog;
use App\Models\PaymentProductOrder;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ActivationService
{
    private static array $activeCache = [];

    public function isActive(User $user): bool
    {
        // DOSB es la raiz corporativa del sistema MLM y nunca vence.
        if ($user->is_admin || strcasecmp((string) $user->uuid, 'DOSB') === 0) return true;
        if (ManualReactivation::where('user_id', $user->id)
            ->where('period', now()->startOfMonth()->toDateString())
            ->where('state', ManualReactivation::ACTIVE)->exists()) return true;
        return $this->isActiveForPeriod($user, now()->startOfMonth(), now()->endOfMonth(), false);
    }

    public function isActiveForCategory(User $user, string $category): bool
    {
        return $this->isActiveForCategoryPeriod(
            $user, $category, now()->startOfMonth(), now()->endOfMonth(), false
        );
    }

    public function isActiveForCategoryPeriod(
        User $user,
        string $category,
        CarbonInterface $from,
        CarbonInterface $to,
        bool $includeClosed = true
    ): bool {
        if ($user->is_admin || strcasecmp((string) $user->uuid, 'DOSB') === 0) return true;

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
        return $rules->contains(function (ActivationRule $rule) use ($packTotals, $totals, $products) {
            $packQualifies = (float) ($packTotals->points ?? 0) >= $rule->minimum_points
                && (float) ($packTotals->amount ?? 0) >= $rule->minimum_amount;
            $monthlyProductsQualify = (float) ($totals->points ?? 0) >= $rule->minimum_points
                && (float) ($totals->amount ?? 0) >= $rule->minimum_amount
                && (int) $products >= $rule->minimum_products;
            return $packQualifies || $monthlyProductsQualify;
        });
    }

    public function isActiveForPeriod(User $user, CarbonInterface $from, CarbonInterface $to, bool $includeClosed = true): bool
    {
        if ($user->is_admin || strcasecmp((string) $user->uuid, 'DOSB') === 0) return true;

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
        $products = (int) ($productCount->products ?? 0);
        $servicePoints = (float) ($service->points ?? 0);
        $serviceAmount = (float) ($service->amount ?? 0);
        $productPoints = (float) ($product->points ?? 0);
        $productAmount = (float) ($product->amount ?? 0);

        return self::$activeCache[$cacheKey] = $rules->contains(function (ActivationRule $rule) use (
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
