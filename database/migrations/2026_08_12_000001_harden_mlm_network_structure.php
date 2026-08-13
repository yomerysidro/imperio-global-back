<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_order_points', function (Blueprint $table) {
            $table->string('type', 2)->change();
            $table->unsignedTinyInteger('level')->nullable()->after('type');
            $table->string('source_user_code')->nullable()->after('sponsor_code');
        });

        Schema::create('sponsor_relations', function (Blueprint $table) {
            $table->id();
            $table->string('user_code')->unique();
            $table->string('sponsor_code')->index();
            $table->string('source', 20)->default('legacy');
            $table->boolean('state')->default(true)->index();
            $table->timestamps();
        });

        // Invitaciones aceptadas tienen prioridad porque expresan una aceptación explícita.
        $validUserCodes = DB::table('users')->pluck('uuid')->all();

        $guests = DB::table('guests_token_users')
            ->where('state', true)
            ->whereColumn('guest_user_code', '!=', 'sponsor_user_code')
            ->whereIn('guest_user_code', $validUserCodes)
            ->whereIn('sponsor_user_code', $validUserCodes)
            ->orderBy('created_at')
            ->get(['guest_user_code', 'sponsor_user_code', 'created_at']);

        foreach ($guests as $guest) {
            DB::table('sponsor_relations')->insertOrIgnore([
                    'sponsor_code' => $guest->sponsor_user_code,
                    'user_code' => $guest->guest_user_code,
                    'source' => 'invitation',
                    'state' => true,
                    'created_at' => $guest->created_at ?? now(),
                    'updated_at' => now(),
            ]);
        }

        // Completa usuarios antiguos desde su primera compra, sin reemplazar invitaciones.
        $purchases = DB::table('payment_order_points')
            ->where('type', 'B')
            ->whereColumn('user_code', '!=', 'sponsor_code')
            ->whereIn('user_code', $validUserCodes)
            ->whereIn('sponsor_code', $validUserCodes)
            ->orderBy('created_at')
            ->get(['user_code', 'sponsor_code', 'created_at']);

        foreach ($purchases->unique('user_code') as $purchase) {
            DB::table('sponsor_relations')->insertOrIgnore([
                'user_code' => $purchase->user_code,
                'sponsor_code' => $purchase->sponsor_code,
                'source' => 'purchase',
                'state' => true,
                'created_at' => $purchase->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }

        // La raíz corporativa tiene una única pierna directa: WAdz.
        DB::table('sponsor_relations')->updateOrInsert(
            ['user_code' => 'WAdz'],
            ['sponsor_code' => 'DOSB', 'source' => 'corporate_root', 'state' => true, 'updated_at' => now()]
        );
        DB::table('sponsor_relations')
            ->where('sponsor_code', 'DOSB')
            ->where('user_code', '!=', 'WAdz')
            ->update(['sponsor_code' => 'WAdz', 'source' => 'corp_normalized', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsor_relations');

        Schema::table('payment_order_points', function (Blueprint $table) {
            $table->dropColumn(['level', 'source_user_code']);
            $table->char('type', 1)->change();
        });
    }
};
