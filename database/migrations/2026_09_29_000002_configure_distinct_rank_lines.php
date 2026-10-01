<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('range_requirements', function (Blueprint $table) {
            $table->boolean('exclusive_line_group')->default(false);
        });

        // Cada grupo marcado necesita sus propias lineas directas. Los grupos
        // sin marcar conservan la distribucion minima que ya estaba en BD.
        $groups = [
            'Oro' => ['Plata' => 2, 'Bronce' => 1],
            'Jade' => ['Oro' => 1, 'Plata' => 2],
            'Rubí' => ['Jade' => 1, 'Oro' => 3],
            'Diamante' => ['Rubí' => 1, 'Jade' => 1, 'Oro' => 1, 'Plata' => 1, 'Bronce' => 1],
            'Doble Diamante' => ['Diamante' => 1, 'Rubí' => 1],
            // Triple Diamante mantiene la distribucion que ya esta configurada.
            'Imperio Global' => [
                'Triple Diamante' => 1, 'Doble Diamante' => 1, 'Diamante' => 1,
                'Rubí' => 1, 'Jade' => 1, 'Oro' => 1, 'Plata' => 1, 'Bronce' => 1,
            ],
        ];
        $ids = DB::table('ranges')->pluck('id', 'title');
        foreach ($groups as $title => $requirements) {
            if (!isset($ids[$title])) continue;
            foreach ($requirements as $required => $lines) {
                if (!isset($ids[$required])) continue;
                DB::table('range_requirements')
                    ->where('range_id', $ids[$title])
                    ->where('required_range_id', $ids[$required])
                    ->update(['minimum_distinct_lines' => $lines,
                        'exclusive_line_group' => true, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('range_requirements', function (Blueprint $table) {
            $table->dropColumn('exclusive_line_group');
        });
    }
};
