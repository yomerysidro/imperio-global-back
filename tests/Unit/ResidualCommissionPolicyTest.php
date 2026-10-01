<?php

namespace Tests\Unit;

use App\Models\CommissionRule;
use App\Models\Range;
use App\Models\RangeRule;
use App\Models\ResidualPoint;
use App\Services\Core\ResidualCommissionPolicy;
use PHPUnit\Framework\TestCase;

class ResidualCommissionPolicyTest extends TestCase
{
    private function policy(): ResidualCommissionPolicy
    {
        $base = new ResidualPoint([
            'level1' => 12.5, 'level2' => 12.5, 'level3' => 16,
            'level4' => 13, 'level5' => 6, 'level6' => 2.5, 'level7' => 1,
        ]);
        $special = collect();
        foreach ([[8, 4, 3], [10, 4, 3], [11, 5, 3], [13, 5, 3], [14, 6, 3], [17, 7, 2]] as [$level, $rank, $percentage]) {
            $rule = new CommissionRule(['category' => 'product', 'level' => $level,
                'percentage' => $percentage, 'state' => true]);
            $rule->setRelation('minimumRange', new Range(['order' => $rank]));
            $special->push($rule);
        }
        return new ResidualCommissionPolicy($base, $special,
            new RangeRule(['depth_to' => 7, 'infinity_percentage' => 1]));
    }

    public function test_base_levels_do_not_require_a_rank(): void
    {
        $policy = $this->policy();
        $this->assertSame([16.0, 'residual'], $policy->rate('product', 3, 0, false, false));
        $this->assertSame([1.0, 'residual'], $policy->rate('service', 7, 0, false, false));
    }

    public function test_special_percentage_replaces_infinity_only_with_valid_rank(): void
    {
        $policy = $this->policy();
        $this->assertSame([1.0, 'infinity'], $policy->rate('product', 8, 0, false, false));
        $this->assertSame([3.0, 'residual'], $policy->rate('product', 10, 4, true, false));
        $this->assertSame([1.0, 'infinity'], $policy->rate('product', 11, 4, true, false));
        $this->assertSame([3.0, 'residual'], $policy->rate('product', 13, 5, true, false));
        $this->assertSame([1.0, 'infinity'], $policy->rate('product', 13, 5, false, false));
        $this->assertSame([2.0, 'residual'], $policy->rate('product', 17, 7, true, false));
        $this->assertSame([1.0, 'infinity'], $policy->rate('product', 27, 9, true, false));
    }
}
