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
        Schema::table('packs', function (Blueprint $table) {
            //
            $table->decimal('discount')->after('image');
        });

        Schema::table('log_payments', function (Blueprint $table) {
            //
            $table->string('log_order_id')->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packs', function (Blueprint $table) {
            $table->dropColumn('discount');
        });

        Schema::table('log_payments', function (Blueprint $table) {
            $table->dropColumn('log_order_id');
        });
    }
};
