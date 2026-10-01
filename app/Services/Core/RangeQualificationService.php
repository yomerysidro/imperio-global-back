<?php

namespace App\Services\Core;

use App\Models\PaymentOrderPoint;
use App\Models\RangeRule;
use App\Models\RangeUser;
use App\Models\User;
use App\Models\PaymentLog;
use App\Models\PaymentProductOrderPoint;
use Illuminate\Support\Facades\DB;

class RangeQualificationService
{
    private array $directCodesCache = [];
    private array $networkCodesCache = [];
    private array $activeCache = [];
    private array $rankActiveCache = [];

    public function __construct(private ?NetworkTreeService $network = null)
    {
        $this->network ??= new NetworkTreeService();
    }

    public function recalculateAll(): array
    {
        ActivationService::clearCache();
        $this->activeCache = [];
        $this->rankActiveCache = [];
        $this->directCodesCache = [];
        $this->networkCodesCache = [];
        $rules = RangeRule::with(['range', 'requirements.requiredRange'])
            ->where('state', true)->get()->sortBy('range.order')->values();
        $users = User::where('is_admin', false)->orWhere('uuid', 'DOSB')->get();
        [$from, $to] = app(ActivationService::class)->visiblePeriod();
        $isGracePeriod = app(ActivationService::class)->isMonthlyGracePeriod();
        $groupPoints = PaymentOrderPoint::whereIn('type', [PaymentOrderPoint::COMPRA, PaymentOrderPoint::GRUPAL])
            ->when(!$isGracePeriod, fn ($query) => $query->where('state', true))
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('user_code, SUM(point) total')->groupBy('user_code')->pluck('total', 'user_code');
        foreach ($users as $user) {
            $key = strtoupper($user->uuid);
            $this->activeCache[$key] = app(ActivationService::class)->isActive($user);
            $this->rankActiveCache[$key] = app(ActivationService::class)->hasRankActivity($user);
        }
        return DB::transaction(function () use ($rules, $users, $groupPoints) {
            RangeUser::whereIn('user_id', $users->pluck('id'))
                ->where('status', true)->update(['status' => false]);
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
        });
    }

    public function recalculateUser(User $user): ?RangeUser
    {
        ActivationService::clearCache();
        $this->activeCache[strtoupper($user->uuid)] = app(ActivationService::class)->isActive($user);
        $this->rankActiveCache[strtoupper($user->uuid)] = app(ActivationService::class)->hasRankActivity($user);
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
        // La cuenta corporativa asciende solo por volumen, sin requisitos de red.
        if (strcasecmp((string) $user->uuid, 'DOSB') === 0) {
            return $groupPoints >= $rule->required_points;
        }

        if (!($this->rankActiveCache[strtoupper($user->uuid)]
            ?? app(ActivationService::class)->hasRankActivity($user))
            || $groupPoints < $rule->required_points) return false;
        $directCodes = $this->directCodes($user->uuid);
        $activeLines = count(array_filter($directCodes, fn ($code) => $this->isActiveCode($code)));
        if ($activeLines < $rule->required_active_lines) return false;

        $exclusiveGroups = [];
        foreach ($rule->requirements as $requirement) {
            $qualifiedCodes = [];
            $qualifiedLines = 0;
            $lineCounts = [];
            foreach ($directCodes as $directCode) {
                $legCodes = $this->networkCodes($directCode);
                $matches = User::whereIn('uuid', $legCodes)
                    ->whereHas('range', fn ($query) => $query->where('status', true)
                        ->whereHas('range', fn ($rangeQuery) => $rangeQuery->where('order', '>=', $requirement->requiredRange->order)))
                    ->get()->filter(fn (User $candidate) => $this->isRankActiveCode($candidate->uuid))->pluck('uuid')->all();
                if ($matches) $qualifiedLines++;
                $lineCounts[$directCode] = count(array_unique($matches));
                $qualifiedCodes = array_merge($qualifiedCodes, $matches);
            }
            if (count(array_unique($qualifiedCodes)) < $requirement->required_count
                || $qualifiedLines < $requirement->minimum_distinct_lines) return false;
            if ($requirement->exclusive_line_group) {
                $exclusiveGroups[] = [
                    'count' => $requirement->required_count,
                    'lines' => $requirement->minimum_distinct_lines,
                    'line_counts' => $lineCounts,
                ];
            }
        }
        return $this->canAllocateDistinctLines($exclusiveGroups);
    }

    private function canAllocateDistinctLines(array $groups, array $used = []): bool
    {
        if (!$groups) return true;
        $group = array_shift($groups);
        $candidates = array_keys(array_filter($group['line_counts'],
            fn ($count, $line) => $count > 0 && !isset($used[$line]), ARRAY_FILTER_USE_BOTH));
        return $this->chooseLines($groups, $group, $candidates, 0, [], 0, $used);
    }

    private function chooseLines(
        array $remainingGroups, array $group, array $candidates,
        int $index, array $chosen, int $people, array $used
    ): bool {
        if (count($chosen) === (int) $group['lines']) {
            if ($people < (int) $group['count']) return false;
            foreach ($chosen as $line) $used[$line] = true;
            return $this->canAllocateDistinctLines($remainingGroups, $used);
        }
        if (count($chosen) + count($candidates) - $index < (int) $group['lines']) return false;
        for ($i = $index; $i < count($candidates); $i++) {
            $line = $candidates[$i];
            if ($this->chooseLines($remainingGroups, $group, $candidates,
                $i + 1, [...$chosen, $line], $people + $group['line_counts'][$line], $used)) return true;
        }
        return false;
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
        return $this->activeCache[$key] = $user ? app(ActivationService::class)->isActive($user) : false;
    }

    private function isRankActiveCode(string $userCode): bool
    {
        $key = strtoupper($userCode);
        if (array_key_exists($key, $this->rankActiveCache)) return $this->rankActiveCache[$key];
        $user = User::where('uuid', $userCode)->first();
        return $this->rankActiveCache[$key] = $user
            ? app(ActivationService::class)->hasRankActivity($user) : false;
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
