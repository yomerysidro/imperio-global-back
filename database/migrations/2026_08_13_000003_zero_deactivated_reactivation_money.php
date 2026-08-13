<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $orderIds = DB::table('manual_reactivations')->where('state', 'deactivated')
            ->whereNotNull('payment_product_order_id')->pluck('payment_product_order_id');
        if ($orderIds->isEmpty()) return;
        DB::table('payment_product_orders')->whereIn('id', $orderIds)
            ->update(['amount' => 0, 'discount' => 0, 'points' => 0]);
        DB::table('payment_product_order_details')->whereIn('payment_product_order_id', $orderIds)
            ->update(['price' => 0, 'subtotal' => 0, 'points' => 0]);
    }

    public function down(): void
    {
        // Los importes simulados anulados no deben restaurarse.
    }
};
