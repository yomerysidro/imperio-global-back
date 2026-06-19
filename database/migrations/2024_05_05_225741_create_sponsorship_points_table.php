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
        Schema::create('sponsorship_points', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('pack_id');
            $table->integer('level1');
            $table->integer('level2');
            $table->integer('level3');
            $table->integer('level4');
            $table->integer('level5');
            $table->timestamps();
        });

        Schema::table('sponsorship_points', function ( $table) {
            $table->foreign('pack_id')
                ->references('id')->on('packs')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sponsorship_points');
    }
};
