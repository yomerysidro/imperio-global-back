<?php

namespace Tests\Unit;

use App\Services\Core\RangeQualificationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RangeLineAllocationTest extends TestCase
{
    public function test_rank_groups_need_different_direct_lines(): void
    {
        $method = new ReflectionMethod(RangeQualificationService::class, 'canAllocateDistinctLines');
        $service = new RangeQualificationService();
        $silver = ['count' => 2, 'lines' => 2,
            'line_counts' => ['A' => 1, 'B' => 1, 'C' => 0]];
        $bronzeThird = ['count' => 2, 'lines' => 1,
            'line_counts' => ['A' => 2, 'B' => 0, 'C' => 2]];
        $bronzeSameLine = ['count' => 2, 'lines' => 1,
            'line_counts' => ['A' => 2, 'B' => 0, 'C' => 0]];

        $this->assertTrue($method->invoke($service, [$silver, $bronzeThird]));
        $this->assertFalse($method->invoke($service, [$silver, $bronzeSameLine]));
    }
}
