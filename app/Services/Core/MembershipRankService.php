<?php

namespace App\Services\Core;

use App\Models\ManualReactivation;
use App\Models\PaymentLog;
use App\Models\PaymentOrderPoint;
use App\Models\SponsorRelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MembershipRankService
{
    public function progress(User $user, CarbonImmutable $now): array
    {
        $from = $now->startOfMonth();
        $storageTimezone = config('app.timezone');
        $period = $from->toDateString();
        $query = DB::table('membership_rank_periods')->where('user_id', $user->id)->where('period', $period);
        $snapshot = $query->first();
        $evaluatedAt = now();
        if ($snapshot && $snapshot->evaluated_at >= $evaluatedAt->copy()->subMinute()->toDateTimeString()) {
            return json_decode($snapshot->evaluation, true, flags: JSON_THROW_ON_ERROR);
        }

        $points = (float) PaymentOrderPoint::where('user_code', $user->uuid)
            ->whereIn('type', [PaymentOrderPoint::COMPRA, PaymentOrderPoint::GRUPAL])
            ->where('state', true)->whereBetween('created_at', [
                $from->setTimezone($storageTimezone), $now->setTimezone($storageTimezone),
            ])
            ->whereHas('paymentOrder.pack', fn ($query) => $query->where('category', 'Servicio'))
            ->sum('point');

        $branches = $this->branches($user->uuid);
        $codes = [$user->uuid, ...array_merge(...array_values($branches))];
        $members = collect(array_chunk($codes, 500))
            ->flatMap(fn (array $chunk) => User::whereIn('uuid', $chunk)->get(['id', 'uuid']))
            ->keyBy(fn (User $member) => strtoupper($member->uuid));
        $active = $this->activatedMembers($members->pluck('id'), $from, $now);
        $isActivated = function (string $code) use ($members, $active): bool {
            $id = $members->get(strtoupper($code))?->id;
            return $id !== null && isset($active[$id]);
        };
        $activeBranchSizes = [];
        foreach ($branches as $front => $codesInBranch) {
            if ($isActivated($front)) {
                $activeBranchSizes[] = count(array_filter($codesInBranch, $isActivated));
            }
        }

        $activationMet = $isActivated($user->uuid);
        $ranks = DB::table('membership_ranks')->orderBy('position')->get()
            ->map(function (object $rank) use ($points, $activationMet, $activeBranchSizes): array {
                $eligibleBranches = count(array_filter($activeBranchSizes,
                    fn (int $size) => $size >= $rank->required_members_per_branch));
                $pointsMet = $points >= $rank->required_points;
                $structureMet = $eligibleBranches >= $rank->required_frontals;

                return [
                    'id' => $rank->id, 'title' => $rank->title, 'position' => $rank->position,
                    'required_points' => $rank->required_points,
                    'required_frontals' => $rank->required_frontals,
                    'required_members_per_branch' => $rank->required_members_per_branch,
                    'eligible_branches' => $eligibleBranches, 'points_met' => $pointsMet,
                    'activation_met' => $activationMet, 'structure_met' => $structureMet,
                    'qualified' => $pointsMet && $activationMet && $structureMet,
                ];
            });
        $current = $ranks->last(fn (array $rank) => $rank['qualified']);
        $next = $ranks->first(fn (array $rank) => $rank['position'] > ($current['position'] ?? 0));
        $result = [
            'points' => $points, 'current' => $current, 'next' => $next,
            'ranks' => $ranks->all(), 'activation_met' => $activationMet,
            'period' => $period, 'closes_at' => $from->addMonth()->toIso8601String(),
        ];

        DB::transaction(function () use ($query, $user, $period, $points, $current, $evaluatedAt, $result) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail(['id']);
            $previous = (clone $query)->first();
            if ($previous && $previous->evaluated_at > $evaluatedAt->toDateTimeString()) return;
            DB::table('membership_rank_periods')->updateOrInsert(['user_id' => $user->id, 'period' => $period], [
                'membership_rank_id' => $current['id'] ?? null,
                'points' => $points,
                'evaluation' => json_encode($result, JSON_THROW_ON_ERROR),
                'qualified_at' => $current
                    ? (($previous?->membership_rank_id == $current['id'] ? $previous->qualified_at : null) ?? $evaluatedAt)
                    : null,
                'evaluated_at' => $evaluatedAt,
            ]);
        }, 3);

        return $result;
    }

    private function branches(string $root): array
    {
        $branches = [];
        $owners = [strtoupper($root) => null];
        $frontier = [$root];
        while ($frontier) {
            $next = [];
            foreach (array_chunk($frontier, 500) as $parents) {
                $relations = SponsorRelation::where('state', true)->whereIn('sponsor_code', $parents)
                    ->get(['user_code', 'sponsor_code']);
                foreach ($relations as $relation) {
                    $code = $relation->user_code;
                    $key = strtoupper($code);
                    if (array_key_exists($key, $owners)) continue;
                    $front = $owners[strtoupper($relation->sponsor_code)] ?? $code;
                    $owners[$key] = $front;
                    $branches[$front][] = $code;
                    $next[] = $code;
                }
            }
            $frontier = $next;
        }

        return $branches;
    }

    private function activatedMembers(Collection $memberIds, CarbonImmutable $from, CarbonImmutable $now): array
    {
        $totals = [];
        $prefix = DB::getTablePrefix();
        $storageTimezone = config('app.timezone');
        $dates = [$from->setTimezone($storageTimezone), $now->setTimezone($storageTimezone)];
        foreach ($memberIds->chunk(500) as $ids) {
            $orders = DB::table('payment_logs as logs')
                ->join('payment_orders as orders', 'orders.id', '=', 'logs.payment_order_id')
                ->join('packs', 'packs.id', '=', 'orders.pack_id')
                ->whereIn('logs.user_id', $ids)->where('logs.state', PaymentLog::PAGADO)
                ->where('packs.category', 'Servicio')->where('orders.amount', '>', 0)
                ->whereBetween('logs.created_at', $dates)
                ->groupBy('logs.user_id')
                ->selectRaw("{$prefix}logs.user_id, SUM({$prefix}orders.amount) amount, SUM({$prefix}packs.points) points")->get();
            foreach ($orders as $order) {
                $totals[$order->user_id] = [(float) $order->amount, (float) $order->points];
            }
            $manual = ManualReactivation::whereIn('user_id', $ids)
                ->where('category', 'service')->where('period', $from->toDateString())
                ->where('state', ManualReactivation::ACTIVE)->get(['user_id', 'amount', 'points']);
            foreach ($manual as $activation) {
                [$amount, $points] = $totals[$activation->user_id] ?? [0, 0];
                $totals[$activation->user_id] = [$amount + $activation->amount, $points + $activation->points];
            }
        }
        return array_filter($totals, fn (array $total) => $total[0] >= 30 && $total[1] >= 30);
    }
}
