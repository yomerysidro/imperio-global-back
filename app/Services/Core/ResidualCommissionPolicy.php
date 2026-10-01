<?php

namespace App\Services\Core;

use App\Models\CommissionRule;
use App\Models\RangeRule;
use App\Models\ResidualPoint;
use Illuminate\Support\Collection;

class ResidualCommissionPolicy
{
    private array $basePercentages = [];
    private array $specialRules = [];
    private int $infinityStartLevel;
    private float $infinityPercentage;

    public function __construct(
        ?ResidualPoint $base = null,
        ?Collection $rules = null,
        ?RangeRule $general = null
    )
    {
        if ($base) {
            for ($level = 1; $level <= 7; $level++) {
                foreach (['product', 'service'] as $category) {
                    $this->basePercentages[$category][$level] = (float) $base->{'level'.$level};
                }
            }
        } else {
            $baseRules = CommissionRule::where('bonus_type', CommissionRule::RESIDUAL)
                ->where('state', true)->whereBetween('level', [1, 7])->get();
            foreach ($baseRules as $rule) {
                $this->basePercentages[$rule->category][$rule->level] = (float) $rule->percentage;
            }
        }

        $rules ??= CommissionRule::with('minimumRange')
            ->where('bonus_type', CommissionRule::RESIDUAL)
            ->where('state', true)
            ->where('level', '>=', 8)->get();
        foreach ($rules as $rule) {
            $this->specialRules[$rule->category][$rule->level] = $rule;
        }

        // Bronce guarda la regla general, aplicable tambien a socios sin rango.
        $general ??= RangeRule::whereHas('range', fn ($query) => $query->where('title', 'Bronce'))
            ->where('state', true)->first();
        $this->infinityStartLevel = $general ? (int) $general->depth_to + 1 : PHP_INT_MAX;
        $this->infinityPercentage = $general ? (float) $general->infinity_percentage : 0.0;
    }

    public function rate(string $category, int $level, int $rankOrder, bool $rankActive, bool $isCompany): array
    {
        if ($level <= 7) {
            return [$this->basePercentages[$category][$level] ?? 0.0, 'residual'];
        }

        $rule = $this->specialRules[$category][$level] ?? null;
        $requiredOrder = (int) ($rule?->minimumRange?->order ?? 0);
        if ($rule && ($isCompany || ($rankActive && $rankOrder >= $requiredOrder))) {
            return [(float) $rule->percentage, 'residual'];
        }

        return $level >= $this->infinityStartLevel
            ? [$this->infinityPercentage, 'infinity']
            : [0.0, 'infinity'];
    }
}
