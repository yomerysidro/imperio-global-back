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
        Schema::create('guests_token_users', function (Blueprint $table) {
            $table->id();
            $table->string("sponsor_user_code");
            $table->string("guest_user_code");
            $table->unsignedBigInteger('invite_user_id');
            $table->foreign('invite_user_id')
                ->references('id')->on('invite_users')->onUpdate('cascade');
            $table->boolean("state");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guests_token_users');
    }
};
