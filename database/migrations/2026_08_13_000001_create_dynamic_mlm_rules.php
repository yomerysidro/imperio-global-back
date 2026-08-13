<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('minimum_points', 12, 2)->default(0);
            $table->decimal('minimum_amount', 12, 2)->default(0);
            $table->unsignedInteger('minimum_products')->default(0);
            $table->boolean('state')->default(true);
            $table->timestamps();
        });

        Schema::create('range_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('range_id')->unique()->constrained('ranges')->cascadeOnUpdate()->cascadeOnDelete();
            $table->decimal('required_points', 14, 2);
            $table->unsignedInteger('required_active_lines')->default(0);
            $table->unsignedInteger('depth_from');
            $table->unsignedInteger('depth_to');
            $table->decimal('infinity_percentage', 8, 4)->default(0);
            $table->boolean('state')->default(true);
            $table->timestamps();
        });

        Schema::create('range_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('range_id')->constrained('ranges')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('required_range_id')->constrained('ranges')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedInteger('required_count');
            $table->unsignedInteger('minimum_distinct_lines')->default(1);
            $table->timestamps();
            $table->unique(['range_id', 'required_range_id']);
        });

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('bonus_type', 30);
            $table->uuid('pack_id')->nullable();
            $table->foreignId('minimum_range_id')->nullable()->constrained('ranges')->cascadeOnUpdate()->nullOnDelete();
            $table->unsignedInteger('level');
            $table->decimal('percentage', 8, 4);
            $table->boolean('state')->default(true);
            $table->timestamps();
            $table->index(['bonus_type', 'pack_id', 'level']);
        });

        DB::table('activation_rules')->insert([
            ['name' => 'Activacion 250 puntos', 'minimum_points' => 250, 'minimum_amount' => 350, 'minimum_products' => 4, 'state' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Activacion 50 puntos', 'minimum_points' => 50, 'minimum_amount' => 100, 'minimum_products' => 1, 'state' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $definitions = [
            'Bronce'          => [1000, 0, 1, 3, 1],
            'Plata'           => [3000, 2, 1, 3, 0],
            'Oro'             => [8000, 3, 4, 6, 0],
            'Jade'            => [28000, 4, 7, 9, 0],
            'Rubí'            => [78000, 4, 10, 12, 0],
            'Diamante'        => [120000, 5, 13, 15, 0],
            'Doble Diamante'  => [320000, 7, 16, 18, 0],
            'Triple Diamante' => [600000, 8, 19, 21, 0],
            'Imperio Global'  => [1600000, 8, 22, 24, 0],
        ];

        $rangeIds = DB::table('ranges')->pluck('id', 'title');
        foreach ($definitions as $title => [$points, $lines, $from, $to, $infinity]) {
            if (!isset($rangeIds[$title])) continue;
            DB::table('ranges')->where('id', $rangeIds[$title])->update(['points' => $points, 'childs' => $lines]);
            DB::table('range_rules')->insert([
                'range_id' => $rangeIds[$title], 'required_points' => $points,
                'required_active_lines' => $lines, 'depth_from' => $from, 'depth_to' => $to,
                'infinity_percentage' => $infinity, 'state' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $requirements = [
            'Plata' => ['Bronce' => 2],
            'Oro' => ['Plata' => 2, 'Bronce' => 2],
            'Jade' => ['Oro' => 1, 'Plata' => 2, 'Bronce' => 4],
            'Rubí' => ['Jade' => 1, 'Oro' => 3, 'Plata' => 3, 'Bronce' => 6],
            'Diamante' => ['Rubí' => 1, 'Jade' => 2, 'Oro' => 3, 'Plata' => 4, 'Bronce' => 8],
            'Doble Diamante' => ['Diamante' => 1, 'Rubí' => 1, 'Jade' => 2, 'Oro' => 4, 'Plata' => 6, 'Bronce' => 10],
            'Triple Diamante' => ['Diamante' => 2, 'Rubí' => 2, 'Jade' => 3, 'Oro' => 5, 'Plata' => 7, 'Bronce' => 15],
            'Imperio Global' => ['Triple Diamante' => 1, 'Doble Diamante' => 1, 'Diamante' => 1, 'Rubí' => 2, 'Jade' => 2, 'Oro' => 4, 'Plata' => 8, 'Bronce' => 25],
        ];
        foreach ($requirements as $title => $items) {
            foreach ($items as $requiredTitle => $count) {
                if (!isset($rangeIds[$title], $rangeIds[$requiredTitle])) continue;
                DB::table('range_requirements')->insert([
                    'range_id' => $rangeIds[$title], 'required_range_id' => $rangeIds[$requiredTitle],
                    'required_count' => $count, 'minimum_distinct_lines' => min($count, $definitions[$title][1]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $percentages = [1 => 18, 2 => 14, 3 => 20, 4 => 6, 5 => 6, 6 => 6, 7 => 4, 8 => 4, 9 => 4,
            10 => 3, 11 => 3, 12 => 3, 13 => 2, 14 => 2, 15 => 2, 16 => 2, 17 => 2, 18 => 2,
            19 => 1, 20 => 1, 21 => 1, 22 => 1, 23 => 1, 24 => 1];
        $seededLevels = [];
        foreach ($definitions as $title => [, , $from, $to]) {
            if (!isset($rangeIds[$title])) continue;
            for ($level = $from; $level <= $to; $level++) {
                if (isset($seededLevels[$level])) continue;
                DB::table('commission_rules')->insert([
                    'bonus_type' => 'residual', 'pack_id' => null, 'minimum_range_id' => $rangeIds[$title],
                    'level' => $level, 'percentage' => $percentages[$level], 'state' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $seededLevels[$level] = true;
            }
        }

        foreach (DB::table('sponsorship_points')->get() as $config) {
            for ($level = 1; $level <= 5; $level++) {
                DB::table('commission_rules')->insert([
                    'bonus_type' => 'sponsorship', 'pack_id' => $config->pack_id, 'minimum_range_id' => null,
                    'level' => $level, 'percentage' => $config->{'level'.$level}, 'state' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('range_requirements');
        Schema::dropIfExists('range_rules');
        Schema::dropIfExists('activation_rules');
    }
};
