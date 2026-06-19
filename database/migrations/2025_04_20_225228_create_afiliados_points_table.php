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
        Schema::create('afiliados_points', function (Blueprint $table) {
            $table->id();
            $table->decimal('level1');
            $table->decimal('level2');
            $table->decimal('level3');
            $table->decimal('level4');
            $table->decimal('level5');
            $table->decimal('level6');
            $table->decimal('level7');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('afiliados_points');
    }
};
