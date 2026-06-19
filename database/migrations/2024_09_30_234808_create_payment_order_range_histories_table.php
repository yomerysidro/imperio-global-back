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
        Schema::create('payment_order_range_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('payment_order_id');
            $table->foreignUuid('pack_id');
            $table->integer('points');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')->on('users')->onUpdate('cascade');
            $table->unsignedBigInteger('range_id');
            $table->foreign('range_id')
                ->references('id')->on('ranges')->onUpdate('cascade');
            $table->boolean('cron')->default(true);
            $table->timestamps();
        });

        Schema::table('payment_order_range_histories', function ( $table) {
            $table->foreign('payment_order_id')
                ->references('id')->on('payment_orders')->onUpdate('cascade');
            $table->foreign('pack_id')
                ->references('id')->on('packs')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_order_range_histories');
    }
};
