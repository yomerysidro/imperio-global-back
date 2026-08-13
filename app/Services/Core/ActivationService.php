<?php

namespace App\Services\Core;

use App\Models\ActivationRule;
use App\Models\PaymentLog;
use App\Models\PaymentProductOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ActivationService
{
    private static array $activeCache = [];

    public function isActive(User $user): bool
    {
        // DOSB es la raiz corporativa del sistema MLM y nunca vence.
        if ($user->is_admin || strcasecmp((string) $user->uuid, 'DOSB') === 0) return true;
        $cacheKey = $user->id . ':' . now()->format('Y-m');
        if (array_key_exists($cacheKey, self::$activeCache)) return self::$activeCache[$cacheKey];
        $rules = ActivationRule::where('state', true)->get();
        if ($rules->isEmpty()) return false;
        $prefix = DB::getTablePrefix();
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();
        $service = DB::table('payment_logs as logs')->join('payment_orders as orders', 'orders.id', '=', 'logs.payment_order_id')
            ->join('packs', 'packs.id', '=', 'orders.pack_id')->where('logs.user_id', $user->id)
            ->where('logs.state', PaymentLog::PAGADO)->whereBetween('logs.created_at', [$from, $to])
            ->selectRaw("COALESCE(SUM({$prefix}packs.points), 0) points, COALESCE(SUM({$prefix}orders.amount), 0) amount")->first();
        $product = DB::table('payment_product_orders as orders')
            ->where('orders.user_id', $user->id)
            ->whereIn('orders.state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO])
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw("COALESCE(SUM({$prefix}orders.points), 0) points, COALESCE(SUM({$prefix}orders.amount), 0) amount")->first();
        $productCount = DB::table('payment_product_order_details as details')
            ->join('payment_product_orders as orders', 'orders.id', '=', 'details.payment_product_order_id')
            ->where('orders.user_id', $user->id)
            ->whereIn('orders.state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO])
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw("COALESCE(SUM({$prefix}details.quantity), 0) products")->first();
        $products = (int) ($productCount->products ?? 0);
        $points = (float) ($service->points ?? 0) + (float) ($product->points ?? 0);
        $amount = (float) ($service->amount ?? 0) + (float) ($product->amount ?? 0);
        return self::$activeCache[$cacheKey] = $rules->contains(fn (ActivationRule $rule) => $points >= $rule->minimum_points
            && $amount >= $rule->minimum_amount && $products >= $rule->minimum_products);
    }

    public static function clearCache(): void { self::$activeCache = []; }
}
