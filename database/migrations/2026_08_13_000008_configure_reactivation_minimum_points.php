<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('options')->updateOrInsert(
            ['option_key' => 'reactivation_min_points'],
            ['option_value' => '200', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        DB::table('options')->where('option_key', 'reactivation_min_points')->delete();
    }
};
