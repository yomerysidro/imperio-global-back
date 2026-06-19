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
        Schema::create('user_email_temps', function (Blueprint $table) {
            $table->id();
            $table->integer("userId");
            $table->boolean("isAdmin")->default(false);
            $table->string("status");
            $table->string("email");
            $table->string("subject");
            $table->integer("month");
            $table->integer("year");
            $table->longText("jsonBody")->nullable();
            $table->longText("jsonError")->nullable();
            $table->string("fileAttachment")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_email_temps');
    }
};
