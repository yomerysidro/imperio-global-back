<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Las comisiones ganadas no vencen cuando termina la activacion mensual.
        // Las anulaciones manuales no se restauran: tienen point = 0 y type = X.
        DB::table('payment_order_points')
            ->whereIn('type', ['P', 'PS', 'S', 'R', 'RS', 'I'])
            ->where('point', '>', 0)
            ->update(['state' => true]);
    }

    public function down(): void
    {
        // No es seguro volver a ocultar comisiones porque no puede distinguirse
        // cuáles estaban inactivas antes de esta correccion.
    }
};
