<?php

namespace Tests\Unit;

use App\Services\Core\ActivationService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ActivationGracePeriodTest extends TestCase
{
    public function test_grace_period_ends_when_monthly_closing_starts(): void
    {
        $service = new ActivationService();

        $this->assertTrue($service->isMonthlyGracePeriod(
            Carbon::create(2026, 9, 3, 0, 59, 59, 'America/Lima')
        ));
        $this->assertFalse($service->isMonthlyGracePeriod(
            Carbon::create(2026, 9, 3, 1, 0, 0, 'America/Lima')
        ));
    }

    public function test_visible_period_is_previous_month_during_grace(): void
    {
        [$from, $to] = (new ActivationService())->visiblePeriod(
            Carbon::create(2026, 9, 1, 12, 0, 0, 'America/Lima')
        );

        $this->assertSame('2026-08-01 00:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-31 23:59:59', $to->format('Y-m-d H:i:s'));
    }
}
