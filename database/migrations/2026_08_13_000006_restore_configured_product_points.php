<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $orderIds = DB::table('manual_reactivations')->where('state', 'deactivated')
            ->whereNotNull('payment_product_order_id')->pluck('payment_product_order_id');
        DB::table('payment_product_order_details')->whereIn('payment_product_order_id', $orderIds)
            ->orderBy('id')->each(function ($detail) {
                $order = DB::table('payment_product_orders')->where('id', $detail->payment_product_order_id)->first();
                $specific = $order?->pack_id ? DB::table('product_point_packs')
                    ->where('product_id', $detail->product_id)->where('pack_id', $order->pack_id)->value('point') : null;
                $configured = DB::table('product_point_packs')->where('product_id', $detail->product_id)->max('point');
                $base = DB::table('products')->where('id', $detail->product_id)->value('points');
                DB::table('payment_product_order_details')->where('id', $detail->id)->update([
                    'points' => (float) ($specific ?? $configured ?? $base ?? 0) * (int) $detail->quantity,
                ]);
            });
    }

    public function down(): void {}
};
