<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_ranks', function (Blueprint $table) {
            $table->id();
            $table->string('title', 40);
            $table->unsignedTinyInteger('position')->unique();
            $table->unsignedInteger('required_points');
            $table->unsignedTinyInteger('required_frontals');
            $table->unsignedTinyInteger('required_members_per_branch');
        });

        foreach ([
            ['Bronce', 500], ['Plata', 1500], ['Oro', 5500], ['Jade', 12000],
            ['Rubí', 25560], ['Diamante', 60000], ['Doble Diamante', 120000],
        ] as $index => [$title, $points]) {
            $position = $index + 1;
            DB::table('membership_ranks')->insert([
                'title' => $title,
                'position' => $position,
                'required_points' => $points,
                'required_frontals' => $position,
                'required_members_per_branch' => $position,
            ]);
        }

        Schema::create('membership_rank_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('period');
            $table->foreignId('membership_rank_id')->nullable()->constrained('membership_ranks');
            $table->decimal('points', 14, 2);
            $table->json('evaluation');
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('evaluated_at');
            $table->unique(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_rank_periods');
        Schema::dropIfExists('membership_ranks');
    }
};
