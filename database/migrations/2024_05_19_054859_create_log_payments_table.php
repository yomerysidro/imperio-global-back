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
        Schema::create('log_payments', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('message')->nullable();
            $table->text('apiController')->nullable();
            $table->text('jsonRequest')->nullable();
            $table->text('jsonResponse')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('log_payments');
    }
};
