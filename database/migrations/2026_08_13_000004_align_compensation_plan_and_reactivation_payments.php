<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_reactivations', function (Blueprint $table) {
            $table->json('payment_order_ids')->nullable()->after('payment_log_ids');
        });

        DB::table('manual_reactivations')->orderBy('id')->each(function ($reactivation) {
            $logIds = json_decode($reactivation->payment_log_ids ?: '[]', true) ?: [];
            $orderIds = DB::table('payment_logs')->whereIn('id', $logIds)
                ->whereNotNull('payment_order_id')->pluck('payment_order_id')->values()->all();
            DB::table('manual_reactivations')->where('id', $reactivation->id)
                ->update(['payment_order_ids' => json_encode($orderIds)]);
            if ($reactivation->state === 'deactivated' && $orderIds) {
                DB::table('payment_orders')->whereIn('id', $orderIds)->update(['amount' => 0]);
            }
        });

        $diamondPackId = DB::table('packs')->where('title', 'Pack Diamante')->value('id');
        if ($diamondPackId) {
            $levels = [1 => 40, 2 => 5, 3 => 3, 4 => 2, 5 => 2];
            DB::table('sponsorship_points')->where('pack_id', $diamondPackId)->update([
                'level1' => 40, 'level2' => 5, 'level3' => 3, 'level4' => 2, 'level5' => 2,
                'updated_at' => now(),
            ]);
            foreach ($levels as $level => $percentage) {
                DB::table('commission_rules')->where('bonus_type', 'sponsorship')
                    ->where('pack_id', $diamondPackId)->where('level', $level)
                    ->update(['percentage' => $percentage, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('manual_reactivations', function (Blueprint $table) {
            $table->dropColumn('payment_order_ids');
        });
    }
};
