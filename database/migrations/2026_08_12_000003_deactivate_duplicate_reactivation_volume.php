<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Corrige únicamente el volumen duplicado de la reactivación de WAdz:
        // la compra real aportó 150; este registro adicional tomó 340 del pack base.
        DB::table('payment_order_points')
            ->where('user_code', 'DOSB')
            ->where('source_user_code', 'WAdz')
            ->where('type', 'G')
            ->where('point', 340)
            ->where('created_at', '2026-08-12 19:16:57')
            ->update(['state' => false]);
    }

    public function down(): void
    {
        DB::table('payment_order_points')
            ->where('user_code', 'DOSB')
            ->where('source_user_code', 'WAdz')
            ->where('type', 'G')
            ->where('point', 340)
            ->where('created_at', '2026-08-12 19:16:57')
            ->update(['state' => true]);
    }
};
