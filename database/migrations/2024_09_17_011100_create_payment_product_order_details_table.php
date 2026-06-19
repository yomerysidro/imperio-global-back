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
        Schema::create('payment_product_order_details', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('payment_product_order_id');
            $table->foreignUuid('product_id');
            $table->string('product_title');
            $table->integer('quantity');
            $table->decimal('price');
            $table->decimal('subtotal');
            $table->integer('points');
            $table->timestamps();
        });

        Schema::table('payment_product_order_details', function ( $table) {
            $table->foreign('payment_product_order_id')
                ->references('id')->on('payment_product_orders')->onUpdate('cascade')->name('fk_pay_prod_ord_id_detail');

            $table->foreign('product_id')
                ->references('id')->on('products')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_product_order_details');
    }
};
