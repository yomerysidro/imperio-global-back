<?php

namespace Tests\Unit;

use App\Services\Core\Calculator;
use App\Models\Pack;
use App\Models\PaymentOrder;
use App\Models\PaymentOrderPoint;
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

    public function test_grouped_members_keep_volume_commissions_and_grace_period_totals(): void
    {
        $calculator = new Calculator();
        $pack = new Pack(['category' => 'Producto']);
        $order = (new PaymentOrder())->setRelation('pack', $pack);
        $purchase = (new PaymentOrderPoint([
            'user_code' => 'socio', 'payment_order_id' => 'purchase', 'point' => 50,
            'state' => 0, 'type' => PaymentOrderPoint::COMPRA,
        ]))->setDateFormat('Y-m-d H:i:s')->forceFill(['created_at' => '2026-10-10 10:00:00'])
            ->setRelation('paymentOrder', $order);
        $commission = (object) [
            'user_code' => 'SOCIO', 'payment_order_id' => 'commission', 'point' => 12,
            'state' => 1, 'type' => PaymentOrderPoint::PATROCINIO, 'created_at' => '2026-10-15 00:00:00',
        ];
        $unrelated = (object) [
            'user_code' => 'OTRO', 'payment_order_id' => 'other', 'point' => 88,
            'state' => 1, 'type' => PaymentOrderPoint::PATROCINIO, 'created_at' => '2026-10-15 00:00:00',
        ];
        $products = [(object) ['created_at' => '2026-10-10', 'state' => 0, 'points' => 15]];
        $volume = $calculator->productVolume($products, 10, 2026, true);

        $before = $calculator->pointsForKnownUser('SOCIO', [$purchase, $unrelated, $commission], $volume, 10, 2026, true);
        $after = $calculator->pointsForKnownUser('SOCIO', [$purchase, $commission], $volume, 10, 2026, true);
        $this->assertEquals($before, $after);
        $this->assertSame(65.0, $after->personal);
        $this->assertSame(12.0, $after->patrocinio);
        $this->assertSame(0.0, $calculator->productVolume($products, 10, 2026, false));
    }
}
