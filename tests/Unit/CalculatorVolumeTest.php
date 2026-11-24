<?php

namespace Tests\Unit;

use App\Services\Core\Calculator;
use PHPUnit\Framework\TestCase;

class CalculatorVolumeTest extends TestCase
{
    public function test_reusing_calculated_points_preserves_the_volume_total(): void
    {
        $points = (object) [
            'personal' => 12.5,
            'pointGroup' => 27.75,
            'total_general' => 400,
            'total_comisiones' => 500,
        ];
        $calculator = $this->createPartialMock(Calculator::class, ['points']);
        $calculator->expects($this->once())->method('points')->willReturn($points);

        $this->assertSame(40.25, $calculator->pointsTotal('SOCIO', collect(), collect()));
        $this->assertSame(40.25, $calculator->volumeTotal($points));
    }
}
