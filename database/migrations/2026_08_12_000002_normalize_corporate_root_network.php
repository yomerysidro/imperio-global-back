<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CORPORATE_ROOT = 'DOSB';
    private const CORPORATE_DIRECT = 'WAdz';

    public function up(): void
    {
        // Regla comercial: DOSB solo tiene un directo; toda la red nace de WAdz.
        DB::table('sponsor_relations')->updateOrInsert(
            ['user_code' => self::CORPORATE_DIRECT],
            [
                'sponsor_code' => self::CORPORATE_ROOT,
                'source' => 'corporate_root',
                'state' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('sponsor_relations')
            ->where('sponsor_code', self::CORPORATE_ROOT)
            ->where('user_code', '!=', self::CORPORATE_DIRECT)
            ->update([
                'sponsor_code' => self::CORPORATE_DIRECT,
                'source' => 'corp_normalized',
                'state' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No se reconstruyen relaciones ambiguas al revertir: los datos originales
        // permanecen disponibles en payment_orders y guests_token_users.
    }
};
