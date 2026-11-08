<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_move_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->index();
            $table->unsignedBigInteger('admin_user_id')->nullable()->index();
            $table->string('user_code', 100)->index();
            $table->string('old_sponsor_code', 100)->nullable();
            $table->string('new_sponsor_code', 100);
            $table->unsignedInteger('descendant_count')->default(0);
            $table->unsignedInteger('total_affected')->default(1);
            $table->char('structure_before_hash', 64);
            $table->char('structure_after_hash', 64);
            $table->string('status', 24)->index();
            $table->json('details')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->engine = 'InnoDB';
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_move_audits');
    }
};
