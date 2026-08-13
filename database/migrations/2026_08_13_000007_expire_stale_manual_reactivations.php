<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('manual_reactivations as r')
            ->join('payment_product_orders as o', 'o.id', '=', 'r.payment_product_order_id')
            ->where('r.state', 'active')->whereNotIn('o.state', [2, 3])
            ->update(['r.state' => 'expired', 'r.deactivated_at' => now(), 'r.updated_at' => now()]);
    }

    public function down(): void {}
};
