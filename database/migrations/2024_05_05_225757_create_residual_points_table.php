<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('residual_points', function (Blueprint $table) {
            $table->id();
            $table->integer('level1');
            $table->integer('level2');
            $table->integer('level3');
            $table->integer('level4');
            $table->integer('level5');
            $table->integer('level6');
            $table->integer('level7');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('residual_points');
    }
};
