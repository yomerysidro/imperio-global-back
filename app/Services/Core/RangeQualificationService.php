<?php

namespace App\Services\Core;

use App\Models\PaymentOrderPoint;
use App\Models\RangeRule;
use App\Models\RangeUser;
use App\Models\User;
use App\Models\PaymentLog;
use App\Models\PaymentProductOrderPoint;

class RangeQualificationService
{
    private array $directCodesCache = [];
    private array $networkCodesCache = [];
    private array $activeCache = [];

    public function __construct(private ?NetworkTreeService $network = null)
    {
        $this->network ??= new NetworkTreeService();
    }

    public function recalculateAll(): array
    {
        ActivationService::clearCache();
        $rules = RangeRule::with(['range', 'requirements.requiredRange'])
            ->where('state', true)->get()->sortBy('range.order')->values();
        $users = User::where('is_admin', false)->get();
        [$from, $to] = app(ActivationService::class)->visiblePeriod();
        $isGracePeriod = app(ActivationService::class)->isMonthlyGracePeriod();
        $groupPoints = PaymentOrderPoint::whereIn('type', [PaymentOrderPoint::COMPRA, PaymentOrderPoint::GRUPAL])
            ->when(!$isGracePeriod, fn ($query) => $query->where('state', true))
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('user_code, SUM(point) total')->groupBy('user_code')->pluck('total', 'user_code');
        foreach ($users as $user) $this->activeCache[strtoupper($user->uuid)] = $user->active;
        RangeUser::where('status', true)->update(['status' => false]);
        $qualified = [];

        foreach ($rules as $rule) {
            foreach ($users as $user) {
                $points = (float) ($groupPoints[$user->uuid] ?? 0);
                if (!$this->qualifies($user, $rule, $points)) continue;
                RangeUser::updateOrCreate(['user_id' => $user->id], ['range_id' => $rule->range_id, 'status' => true]);
                $qualified[$user->uuid] = $rule->range->title;
            }
        }

        return $qualified;
    }

    public function recalculateUser(User $user): ?RangeUser
    {
        ActivationService::clearCache();
        $this->activeCache[strtoupper($user->uuid)] = $user->active;
        [$from, $to] = app(ActivationService::class)->visiblePeriod();
        $isGracePeriod = app(ActivationService::class)->isMonthlyGracePeriod();
        $points = (float) PaymentOrderPoint::where('user_code', $user->uuid)
            ->whereIn('type', [PaymentOrderPoint::COMPRA, PaymentOrderPoint::GRUPAL])
            ->when(!$isGracePeriod, fn ($query) => $query->where('state', true))
            ->whereBetween('created_at', [$from, $to])->sum('point');
        $qualifiedRule = null;
        foreach (RangeRule::with(['range', 'requirements.requiredRange'])
            ->where('state', true)->get()->sortBy('range.order') as $rule) {
            if ($this->qualifies($user, $rule, $points)) $qualifiedRule = $rule;
        }

        if (!$qualifiedRule) {
            RangeUser::where('user_id', $user->id)->update(['status' => false]);
            return null;
        }

        return RangeUser::updateOrCreate(
            ['user_id' => $user->id],
            ['range_id' => $qualifiedRule->range_id, 'status' => true]
        );
    }

    public function qualifies(User $user, RangeRule $rule, float $groupPoints): bool
    {
        if (!($this->activeCache[strtoupper($user->uuid)] ?? $user->active) || $groupPoints < $rule->required_points) return false;
        $directCodes = $this->directCodes($user->uuid);
        $activeLines = count(array_filter($directCodes, fn ($code) => $this->isActiveCode($code)));
        if ($activeLines < $rule->required_active_lines) return false;

        foreach ($rule->requirements as $requirement) {
            $qualifiedCodes = [];
            $qualifiedLines = 0;
            foreach ($directCodes as $directCode) {
                $legCodes = $this->networkCodes($directCode);
                $matches = User::whereIn('uuid', $legCodes)
                    ->whereHas('range', fn ($query) => $query->where('status', true)
                        ->whereHas('range', fn ($rangeQuery) => $rangeQuery->where('order', '>=', $requirement->requiredRange->order)))
                    ->get()->filter(fn (User $candidate) => $this->isActiveCode($candidate->uuid))->pluck('uuid')->all();
                if ($matches) $qualifiedLines++;
                $qualifiedCodes = array_merge($qualifiedCodes, $matches);
            }
            if (count(array_unique($qualifiedCodes)) < $requirement->required_count
                || $qualifiedLines < $requirement->minimum_distinct_lines) return false;
        }
        return true;
    }

    private function directCodes(string $userCode): array
    {
        return $this->directCodesCache[strtoupper($userCode)] ??=
            array_values(array_unique($this->network->directUserCodes($userCode)));
    }

    private function networkCodes(string $userCode): array
    {
        return $this->networkCodesCache[strtoupper($userCode)] ??=
            array_values(array_unique($this->network->getAllNetworkUsers($userCode)));
    }

    private function isActiveCode(string $userCode): bool
    {
        $key = strtoupper($userCode);
        if (array_key_exists($key, $this->activeCache)) return $this->activeCache[$key];
        $user = User::where('uuid', $userCode)->first();
        return $this->activeCache[$key] = $user ? $user->active : false;
    }

    public function infinityPercentage(User $user): float
    {
        return (float) ($user->range?->range?->rule?->infinity_percentage ?? 0);
    }

    public function distributeInfinity(): array
    {
        $result = [];
        foreach (User::with('range.range.rule')->where('is_admin', false)->get() as $user) {
            $percentage = $this->infinityPercentage($user);
            $firstLevel = $this->firstInfinityLevel($user);
            if (!$user->active || $percentage <= 0 || $firstLevel === null) continue;

            $ownOrder = (int) ($user->range?->range?->order ?? 0);
            $allDescendants = $this->networkCodes($user->uuid);
            $hasBreakaway = User::whereIn('uuid', $allDescendants)->where('uuid', '!=', $user->uuid)
                ->whereHas('range', fn ($query) => $query->where('status', true)
                    ->whereHas('range', fn ($range) => $range->where('order', '>=', $ownOrder)))->exists();
            if ($hasBreakaway) continue;

            $frontier = [$user->uuid];
            $visited = [strtoupper($user->uuid) => true];
            $eligibleCodes = [];
            $level = 0;
            while ($frontier) {
                $level++;
                $next = [];
                foreach ($frontier as $code) {
                    foreach ($this->directCodes($code) as $childCode) {
                        $key = strtoupper($childCode);
                        if (isset($visited[$key])) continue;
                        $visited[$key] = true;
                        $next[] = $childCode;
                        if ($level >= $firstLevel) $eligibleCodes[] = $childCode;
                    }
                }
                $frontier = $next;
            }

            $volume = (float) PaymentOrderPoint::whereIn('user_code', $eligibleCodes)
                ->where('type', PaymentOrderPoint::COMPRA)->where('state', true)->sum('point');
            $eligibleIds = User::whereIn('uuid', $eligibleCodes)->pluck('id');
            $volume += (float) PaymentProductOrderPoint::whereIn('user_id', $eligibleIds)->where('state', true)->sum('points');
            $bonus = round($volume * $percentage / 100, 2);
            $paymentOrderId = PaymentLog::where('user_id', $user->id)
                ->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO])->latest()->value('payment_order_id');
            if ($bonus <= 0 || !$paymentOrderId) continue;

            PaymentOrderPoint::updateOrCreate([
                'payment_order_id' => $paymentOrderId, 'user_code' => $user->uuid,
                'type' => PaymentOrderPoint::INFINITO, 'level' => $firstLevel,
            ], ['sponsor_code' => $this->network->sponsorCode($user->uuid) ?? '', 'point' => $bonus,
                'payment' => false, 'user_id' => $user->id, 'state' => true]);
            $result[$user->uuid] = $bonus;
        }
        return $result;
    }

    public function firstInfinityLevel(User $user): ?int
    {
        $depth = $user->range?->range?->rule?->depth_to;
        return $depth === null ? null : ((int) $depth + 1);
    }
}
