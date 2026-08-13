<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_reactivations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('activated_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->uuid('payment_product_order_id')->nullable();
            $table->json('payment_order_point_ids')->nullable();
            $table->json('payment_product_order_point_ids')->nullable();
            $table->json('payment_log_ids')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('points', 12, 2)->default(0);
            $table->string('state', 20)->default('active');
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'state']);
        });
        Schema::table('payment_order_points', function (Blueprint $table) {
            $table->foreignId('manual_reactivation_id')->nullable()->after('id')
                ->constrained('manual_reactivations')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_order_points', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manual_reactivation_id');
        });
        Schema::dropIfExists('manual_reactivations');
    }
};
