<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    // Retired data repair: retain migration history without rewriting existing data.
    public function up(): void {}
    public function down(): void {}
};