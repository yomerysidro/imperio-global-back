<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing duplicates deliberately fail deployment; never silently merge accounts.
        Schema::table('users', fn (Blueprint $table) => $table->unique('uuid', 'users_uuid_unique'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropUnique('users_uuid_unique'));
    }
};
