<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactivation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category', 20)->unique();
            $table->decimal('minimum_points', 12, 2)->default(0);
            $table->decimal('minimum_amount', 12, 2)->default(0);
            $table->unsignedInteger('minimum_products')->default(0);
            $table->boolean('state')->default(true);
            $table->timestamps();
        });

        DB::table('reactivation_rules')->insert([
            ['name' => 'Reactivacion por productos', 'category' => 'product', 'minimum_points' => 50,
                'minimum_amount' => 0, 'minimum_products' => 0, 'state' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Reactivacion por servicios', 'category' => 'service', 'minimum_points' => 30,
                'minimum_amount' => 0, 'minimum_products' => 0, 'state' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('manual_reactivations', function (Blueprint $table) {
            $table->string('category', 20)->default('product')->after('activated_by');
            $table->date('period')->nullable()->after('category');
            $table->decimal('minimum_points', 12, 2)->default(0)->after('points');
            $table->index(['user_id', 'category', 'period', 'state'], 'manual_reactivation_category_period_idx');
        });
        DB::table('manual_reactivations')->whereNull('period')
            ->update(['category' => 'product', 'period' => DB::raw("DATE_FORMAT(created_at, '%Y-%m-01')")]);

        Schema::table('commission_rules', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('bonus_type');
            $table->index(['bonus_type', 'category', 'level'], 'commission_bonus_category_level_idx');
        });
        DB::table('commission_rules')->where('bonus_type', 'residual')->update(['category' => 'product']);
        foreach ([1 => 10, 2 => 5, 3 => 10] as $level => $percentage) {
            DB::table('commission_rules')->insert([
                'bonus_type' => 'residual', 'category' => 'service', 'pack_id' => null,
                'minimum_range_id' => null, 'level' => $level, 'percentage' => $percentage,
                'state' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $servicePackIds = DB::table('packs')->whereRaw('UPPER(category) = ?', ['SERVICIO'])->pluck('id');
        $serviceProductIds = DB::table('products')->whereRaw('LOWER(title) LIKE ?', ['%servicio%'])->pluck('id');
        DB::table('product_point_packs')->whereIn('pack_id', $servicePackIds)
            ->whereIn('product_id', $serviceProductIds)->update(['point' => 30, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $servicePackIds = DB::table('packs')->whereRaw('UPPER(category) = ?', ['SERVICIO'])->pluck('id');
        $serviceProductIds = DB::table('products')->whereRaw('LOWER(title) LIKE ?', ['%servicio%'])->pluck('id');
        DB::table('product_point_packs')->whereIn('pack_id', $servicePackIds)
            ->whereIn('product_id', $serviceProductIds)->update(['point' => 50, 'updated_at' => now()]);
        $serviceRuleIds = DB::table('commission_rules')->where('bonus_type', 'residual')
            ->where('category', 'service')->pluck('id');
        DB::table('commission_rules')->whereIn('id', $serviceRuleIds)->delete();
        DB::table('commission_rules')->where('bonus_type', 'residual')->update(['category' => null]);
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->dropIndex('commission_bonus_category_level_idx');
            $table->dropColumn('category');
        });
        Schema::table('manual_reactivations', function (Blueprint $table) {
            $table->dropIndex('manual_reactivation_category_period_idx');
            $table->dropColumn(['category', 'period', 'minimum_points']);
        });
        Schema::dropIfExists('reactivation_rules');
    }
};
