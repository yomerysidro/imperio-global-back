<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Reemplazada por reactivation_rules, separada por product/service.
        DB::table('options')->where('option_key', 'reactivation_min_points')->delete();
    }

    public function down(): void
    {
        DB::table('options')->updateOrInsert(
            ['option_key' => 'reactivation_min_points'],
            ['option_value' => '150', 'created_at' => now(), 'updated_at' => now()]
        );
    }
};
