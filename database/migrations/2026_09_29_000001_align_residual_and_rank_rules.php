<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Los porcentajes fraccionarios deben conservar centimos al acreditarse.
        Schema::table('payment_order_points', function (Blueprint $table) {
            $table->decimal('point', 14, 2)->change();
        });

        $now = now();
        DB::table('activation_rules')->where('minimum_points', 250)->update([
            'name' => 'Activacion 200 puntos', 'minimum_points' => 200,
            'minimum_amount' => 350, 'minimum_products' => 4, 'updated_at' => $now,
        ]);
        DB::table('activation_rules')->where('minimum_points', 50)->update([
            'minimum_amount' => 100, 'minimum_products' => 0, 'updated_at' => $now,
        ]);

        $base = [1 => 12.5, 2 => 12.5, 3 => 16, 4 => 13, 5 => 6, 6 => 2.5, 7 => 1];
        DB::table('residual_points')->where('id', 1)->update([
            'level1' => 12.5, 'level2' => 12.5, 'level3' => 16, 'level4' => 13,
            'level5' => 6, 'level6' => 2.5, 'level7' => 1, 'updated_at' => $now,
        ]);

        $generations = [
            'Jade' => [8, 10, 3],
            'Rubí' => [11, 13, 3],
            'Diamante' => [14, 16, 3],
            'Doble Diamante' => [17, 19, 2],
            'Triple Diamante' => [20, 22, 1],
            'Imperio Global' => [23, 26, 1],
        ];
        $ranges = DB::table('ranges')->pluck('id', 'title');
        foreach ($ranges as $title => $id) {
            $depth = $generations[$title] ?? [1, 7, 1];
            DB::table('range_rules')->where('range_id', $id)->update([
                'depth_from' => $depth[0], 'depth_to' => $depth[1],
                'infinity_percentage' => 1, 'updated_at' => $now,
            ]);
        }

        foreach (['product', 'service'] as $category) {
            foreach ($base as $level => $percentage) {
                $this->putRule($category, $level, $percentage, null, $now);
            }
            foreach ($generations as $title => [$from, $to, $percentage]) {
                if (!isset($ranges[$title])) continue;
                for ($level = $from; $level <= $to; $level++) {
                    $this->putRule($category, $level, $percentage, $ranges[$title], $now);
                }
            }
        }
    }

    private function putRule(string $category, int $level, float $percentage, ?int $rangeId, $now): void
    {
        $query = DB::table('commission_rules')->where('bonus_type', 'residual')
            ->where('category', $category)->where('level', $level);
        $values = ['percentage' => $percentage, 'minimum_range_id' => $rangeId,
            'state' => true, 'updated_at' => $now];
        if ((clone $query)->exists()) {
            $query->update($values);
        } else {
            DB::table('commission_rules')->insert($values + [
                'bonus_type' => 'residual', 'category' => $category,
                'pack_id' => null, 'level' => $level, 'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Los movimientos ya acreditados pueden tener centimos; no se reduce
        // point a entero ni se revierten reglas comerciales editadas despues.
    }
};
