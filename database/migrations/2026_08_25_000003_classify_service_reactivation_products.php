<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('reactivation_category', 20)->default('product')->after('points')->index();
        });

        $serviceProducts = DB::table('products')->whereRaw('LOWER(title) LIKE ?', ['%servicio%'])->pluck('id');
        DB::table('products')->whereIn('id', $serviceProducts)
            ->update(['reactivation_category' => 'service', 'updated_at' => now()]);

        $servicePackId = DB::table('packs')->whereRaw('UPPER(category) = ?', ['SERVICIO'])->value('id');
        if (!$servicePackId || $serviceProducts->isEmpty()) return;

        $wrongReactivations = DB::table('manual_reactivations as r')
            ->join('payment_product_order_details as d', 'd.payment_product_order_id', '=', 'r.payment_product_order_id')
            ->where('r.category', 'product')->where('r.state', 'active')
            ->whereIn('d.product_id', $serviceProducts)->distinct()->pluck('r.id');

        foreach ($wrongReactivations as $reactivationId) {
            $reactivation = DB::table('manual_reactivations')->where('id', $reactivationId)->first();
            if (!$reactivation) continue;
            DB::table('manual_reactivations')->where('id', $reactivationId)->update([
                'category' => 'service', 'points' => 30, 'minimum_points' => 30, 'updated_at' => now(),
            ]);
            DB::table('payment_product_orders')->where('id', $reactivation->payment_product_order_id)
                ->update(['pack_id' => $servicePackId, 'points' => 30, 'updated_at' => now()]);
            DB::table('payment_product_order_details')->where('payment_product_order_id', $reactivation->payment_product_order_id)
                ->whereIn('product_id', $serviceProducts)->update(['points' => 30, 'updated_at' => now()]);
            DB::table('payment_product_order_points')->where('payment_product_order_id', $reactivation->payment_product_order_id)
                ->update(['points' => 30, 'updated_at' => now()]);
            DB::table('payment_order_points')->where('manual_reactivation_id', $reactivationId)
                ->whereIn('type', ['B', 'G'])->update(['point' => 30, 'updated_at' => now()]);
            DB::table('payment_order_points')->where('manual_reactivation_id', $reactivationId)
                ->where('type', 'R')->update(['point' => 0, 'state' => false, 'type' => 'X', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['reactivation_category']);
            $table->dropColumn('reactivation_category');
        });
    }
};
