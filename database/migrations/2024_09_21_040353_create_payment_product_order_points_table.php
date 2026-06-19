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
        Schema::create('payment_product_order_points', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('payment_product_order_id');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')->on('users')->onUpdate('cascade');
            $table->integer('points');
            $table->boolean('state')->default(true);
            $table->timestamps();
        });

        Schema::table('payment_product_order_points', function ( $table) {
            $table->foreign('payment_product_order_id')
                ->references('id')->on('payment_product_orders')->onUpdate('cascade')->name('fk_pay_prod_ord_id_point');
        });

        Schema::table('payment_product_orders', function ( $table) {

            $table->foreignUuid('pack_id')->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_product_order_points');
    }
};
