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
            ->where('points', 0)->orderBy('id')->each(function ($detail) {
                $order = DB::table('payment_product_orders')->where('id', $detail->payment_product_order_id)->first();
                $specificPoints = $order?->pack_id
                    ? DB::table('product_point_packs')->where('product_id', $detail->product_id)
                        ->where('pack_id', $order->pack_id)->value('point')
                    : null;
                $basePoints = DB::table('products')->where('id', $detail->product_id)->value('points');
                $pointsPerUnit = (float) ($specificPoints ?? $basePoints ?? 0);
                DB::table('payment_product_order_details')->where('id', $detail->id)
                    ->update(['points' => $pointsPerUnit * (int) $detail->quantity]);
            });
    }

    public function down(): void
    {
        // Los puntos de referencia del producto no deben volver a eliminarse.
    }
};
