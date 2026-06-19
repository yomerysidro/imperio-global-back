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
        Schema::create('invite_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sponsor_user_id');
            $table->foreign('sponsor_user_id')
                ->references('id')->on('users')->onUpdate('cascade');
            $table->string("sponsor_user_code");
            $table->text("token");
            $table->boolean("state");
            $table->integer("type");
            $table->dateTime("expired_time");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invite_users');
    }
};
