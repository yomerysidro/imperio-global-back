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
        Schema::create('payment_product_orders', function (Blueprint $table) {
            $table->uuid('id')->primary()->unique();
            $table->string('currency');
            $table->decimal('amount');
            $table->decimal('discount');
            $table->integer('points');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')->on('users')->onUpdate('cascade');
            $table->string('phone');
            $table->text('address');
            $table->integer('state');
            $table->integer('type');
            $table->string('token')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_product_orders');
    }
};
