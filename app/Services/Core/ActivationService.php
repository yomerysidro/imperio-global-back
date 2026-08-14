<?php

namespace App\Services\Core;

use App\Models\ActivationRule;
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
        return $this->isActiveForPeriod($user, now()->startOfMonth(), now()->endOfMonth(), false);
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
