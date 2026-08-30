<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_promotion')->default(false)->after('state');
            $table->dateTime('promotion_start_at')->nullable()->after('is_promotion');
            $table->dateTime('promotion_end_at')->nullable()->after('promotion_start_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_promotion', 'promotion_start_at', 'promotion_end_at']);
        });
    }
};
