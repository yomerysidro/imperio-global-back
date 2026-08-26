<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sponsor_relations')) {
            return;
        }

        $usersByCode = DB::table('users')->get(['uuid'])->keyBy(
            fn ($user) => strtoupper((string) $user->uuid)
        );
        $existingCodes = DB::table('sponsor_relations')->get(['user_code'])
            ->mapWithKeys(fn ($relation) => [strtoupper((string) $relation->user_code) => true]);

        // Antes de sponsor_relations, los puntos S guardaban al beneficiario
        // en user_code y a su patrocinador superior en sponsor_code. La primera
        // relacion cronologica reconstruye la genealogia original sin pisar
        // invitaciones ni compras que ya fueron migradas.
        $legacyRelations = DB::table('payment_order_points')
            ->where('type', 'S')
            ->whereColumn('user_code', '!=', 'sponsor_code')
            ->orderBy('created_at')
            ->get(['user_code', 'sponsor_code', 'created_at'])
            ->unique(fn ($point) => strtoupper((string) $point->user_code));

        foreach ($legacyRelations as $relation) {
            $userCode = strtoupper((string) $relation->user_code);
            $sponsorCode = strtoupper((string) $relation->sponsor_code);

            if (isset($existingCodes[$userCode])
                || !$usersByCode->has($userCode)
                || !$usersByCode->has($sponsorCode)
                || $userCode === $sponsorCode
                || $userCode === 'DOSB') {
                continue;
            }

            DB::table('sponsor_relations')->insertOrIgnore([
                'user_code' => $usersByCode[$userCode]->uuid,
                'sponsor_code' => $usersByCode[$sponsorCode]->uuid,
                'source' => 'legacy_sponsorship',
                'state' => true,
                'created_at' => $relation->created_at ?? now(),
                'updated_at' => now(),
            ]);
            $existingCodes[$userCode] = true;
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sponsor_relations')) {
            DB::table('sponsor_relations')->where('source', 'legacy_sponsorship')->delete();
        }
    }
};
