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
        Schema::create('payment_order_points', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('payment_order_id');
            $table->string('user_code');
            $table->string('sponsor_code');
            $table->integer('point');
            $table->boolean('payment');
            $table->timestamps();
        });

        Schema::table('payment_order_points', function ( $table) {
            $table->foreign('payment_order_id')
                ->references('id')->on('payment_orders')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_order_points');
    }

};
