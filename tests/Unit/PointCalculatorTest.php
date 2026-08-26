<?php

namespace Tests\Unit;

use App\Models\PaymentOrderPoint;
use App\Services\Core\PointCalculator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class PointCalculatorTest extends TestCase
{
    public function test_it_separates_volume_from_commission_and_supports_two_character_types(): void
    {
        $points = collect([
            $this->point('USR1', 'B', 100),
            $this->point('USR1', 'G', 300),
            $this->point('USR1', 'P', 50),
            $this->point('USR1', 'PS', 25),
            $this->point('USR1', 'R', 14),
            $this->point('USR1', 'RS', 6),
            $this->point('OTHER', 'P', 999),
        ]);

        $result = (new PointCalculator())->points('usr1', $points, new Collection());

        $this->assertSame(100, $result->personal);
        $this->assertSame(300, $result->pointGroup);
        $this->assertSame(75, $result->patrocinio);
        $this->assertSame(20, $result->residual);
        $this->assertSame(14, $result->residualProducto);
        $this->assertSame(6, $result->residualServicio);
        $this->assertSame(400, $result->total_general);
        $this->assertSame(95, $result->total_comisiones);
        $this->assertSame(95, $result->bono_total);
        $this->assertSame(95, $result->bonos_totales);
        $this->assertSame(95, $result->ganancia_total);
        $this->assertSame(400, (new PointCalculator())->pointsTotal('usr1', $points, new Collection()));
    }

    public function test_it_does_not_inflate_duplicate_commissions_for_the_same_order_and_level(): void
    {
        $first = $this->point('USR1', 'P', 50);
        $first->id = 1; $first->payment_order_id = 'ORDER-1'; $first->level = 1;
        $duplicate = $this->point('USR1', 'P', 50);
        $duplicate->id = 2; $duplicate->payment_order_id = 'ORDER-1'; $duplicate->level = 1;
        $residual = $this->point('USR1', 'R', 36);
        $residual->id = 3; $residual->payment_order_id = 'ORDER-2'; $residual->level = 1;

        $result = (new PointCalculator())->points('USR1', collect([$first, $duplicate, $residual]), collect());

        $this->assertSame(50, $result->patrocinio);
        $this->assertSame(36, $result->residual);
        $this->assertSame(86, $result->bono_total);
    }

    public function test_it_keeps_legitimate_commissions_from_different_sources_and_ignores_inactive_rows(): void
    {
        $firstSource = $this->point('USR1', 'R', 18);
        $firstSource->id = 1; $firstSource->payment_order_id = 'ORDER-1';
        $firstSource->source_user_code = 'SOURCE-A'; $firstSource->level = 1;

        $secondSource = $this->point('USR1', 'R', 14);
        $secondSource->id = 2; $secondSource->payment_order_id = 'ORDER-1';
        $secondSource->source_user_code = 'SOURCE-B'; $secondSource->level = 2;

        $inactive = $this->point('USR1', 'R', 1000);
        $inactive->id = 3; $inactive->payment_order_id = 'ORDER-2';
        $inactive->source_user_code = 'SOURCE-C'; $inactive->level = 1;
        $inactive->state = false;

        $result = (new PointCalculator())->points(
            'USR1',
            collect([$firstSource, $secondSource, $inactive]),
            collect()
        );

        $this->assertSame(32, $result->residual);
        $this->assertSame(32, $result->bono_total);
    }

    public function test_it_keeps_the_original_entry_instead_of_the_largest_duplicate(): void
    {
        $original = $this->point('USR1', 'P', 40);
        $original->id = 1; $original->payment_order_id = 'ORDER-1';
        $original->source_user_code = 'SOURCE-A'; $original->level = 1;

        $inflatedRetry = $this->point('USR1', 'P', 90);
        $inflatedRetry->id = 2; $inflatedRetry->payment_order_id = 'ORDER-1';
        $inflatedRetry->source_user_code = 'SOURCE-A'; $inflatedRetry->level = 1;

        $result = (new PointCalculator())->points('USR1', collect([$original, $inflatedRetry]), collect());

        $this->assertSame(40, $result->patrocinio);
        $this->assertSame(40, $result->bono_total);
    }

    private function point(string $userCode, string $type, int $amount): PaymentOrderPoint
    {
        return new PaymentOrderPoint([
            'user_code' => $userCode,
            'type' => $type,
            'point' => $amount,
            'state' => true,
        ]);
    }
}
