<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $imperio = DB::table('ranges')->where('title', 'Imperio Global')->value('id');
        $bronze = DB::table('ranges')->where('title', 'Bronce')->value('id');
        if ($imperio && $bronze) {
            DB::table('range_requirements')->where('range_id', $imperio)
                ->where('required_range_id', $bronze)->update([
                    'minimum_distinct_lines' => 8,
                    'exclusive_line_group' => false,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // La distribucion comercial puede editarse posteriormente desde BD.
    }
};
