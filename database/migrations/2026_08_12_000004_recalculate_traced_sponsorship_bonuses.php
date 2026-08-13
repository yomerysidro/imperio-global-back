<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payment_order_points')
            ->whereIn('type', ['P', 'PS'])
            ->whereNotNull('source_user_code')
            ->whereNotNull('level')
            ->orderBy('id')
            ->chunkById(200, function ($bonuses) {
                foreach ($bonuses as $bonus) {
                    $order = DB::table('payment_orders')->where('id', $bonus->payment_order_id)->first();
                    if (!$order || !$order->pack_id || $bonus->level < 1 || $bonus->level > 5) continue;

                    $config = DB::table('sponsorship_points')->where('pack_id', $order->pack_id)->first();
                    $pack = DB::table('packs')->where('id', $order->pack_id)->first();
                    $field = 'level' . (int) $bonus->level;
                    if (!$config || !$pack || !isset($config->{$field})) continue;

                    DB::table('payment_order_points')->where('id', $bonus->id)->update([
                        'point' => round((float) $pack->points * (float) $config->{$field} / 100, 2),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // No se restauran importes que fueron calculados con una configuración ajena.
    }
};
