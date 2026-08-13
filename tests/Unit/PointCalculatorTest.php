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
        $this->assertSame(400, $result->total_general);
        $this->assertSame(95, $result->total_comisiones);
        $this->assertSame(400, (new PointCalculator())->pointsTotal('usr1', $points, new Collection()));
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
