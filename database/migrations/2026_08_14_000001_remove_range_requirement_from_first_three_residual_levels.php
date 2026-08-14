<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('commission_rules')
            ->where('bonus_type', 'residual')
            ->whereBetween('level', [1, 3])
            ->update([
                'minimum_range_id' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $bronzeRangeId = DB::table('ranges')
            ->where('title', 'Bronce')
            ->value('id');

        if (!$bronzeRangeId) {
            return;
        }

        DB::table('commission_rules')
            ->where('bonus_type', 'residual')
            ->whereBetween('level', [1, 3])
            ->update([
                'minimum_range_id' => $bronzeRangeId,
                'updated_at' => now(),
            ]);
    }
};
