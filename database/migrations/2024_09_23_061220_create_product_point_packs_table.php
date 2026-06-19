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
        Schema::create('product_point_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('product_id');
            $table->foreignUuid('pack_id');
            $table->integer('point');
            $table->timestamps();
        });

        Schema::table('product_point_packs', function ( $table) {
            $table->foreign('pack_id')
                ->references('id')->on('packs')->onUpdate('cascade');
            $table->foreign('product_id')
                ->references('id')->on('products')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_point_packs');
    }
};
