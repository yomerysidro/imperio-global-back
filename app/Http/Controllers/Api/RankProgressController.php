<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Models\Range;
use App\Models\User;
use App\Services\Core\ActivationService;
use App\Services\Core\MembershipRankService;
use App\Services\Core\RangeQualificationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RankProgressController extends BaseController
{
    public function show(
        Request $request,
        MembershipRankService $membershipRanks,
        ActivationService $activation,
        RangeQualificationService $rangeQualification,
    )
    {
        $input = $request->validate(['member_id' => ['nullable', 'integer', 'min:1']]);
        $user = $request->user();
        if (isset($input['member_id']) && (int) $input['member_id'] !== $user->id) {
            abort_unless($user->is_admin, 403);
            $user = User::findOrFail($input['member_id']);
        }
        $now = CarbonImmutable::now('America/Lima');
        [$from, $to] = $activation->visiblePeriod($now);
        $points = $rangeQualification->volumeFor($user, $from, $to, $activation->isMonthlyGracePeriod($now));
        $current = $rangeQualification->qualifiedRuleFor($user, $points)?->range;
        $ranks = Range::with('file', 'rule.requirements.requiredRange')
            ->where('state', true)->orderBy('order')->get()->map(fn (Range $rank) => [
                'id' => $rank->id, 'title' => $rank->title, 'position' => $rank->order,
                'required_points' => $rank->rule?->required_points ?? $rank->points,
                'required_frontals' => $rank->rule?->required_active_lines ?? $rank->childs,
                'points_met' => $points >= ($rank->rule?->required_points ?? $rank->points),
                'badge_path' => $rank->getRelation('file')?->path,
                'requirements' => $rank->rule?->requirements->map(fn ($rule) => [
                    'title' => $rule->requiredRange?->title, 'count' => $rule->required_count,
                    'distinct_lines' => $rule->minimum_distinct_lines,
                ])->all() ?? [],
            ]);

        $badges = $ranks->keyBy(fn (array $rank) => Str::slug($rank['title']));
        $service = $membershipRanks->progress($user, $now);
        $service['ranks'] = array_map(function (array $rank) use ($badges): array {
            $rank['badge_path'] = $badges->get(Str::slug($rank['title']))['badge_path'] ?? null;
            return $rank;
        }, $service['ranks']);

        return $this->sendResponse([
            'member' => $user->only('id', 'name', 'uuid'),
            'can_search_members' => (bool) $request->user()->is_admin,
            'server_time' => $now->toIso8601String(),
            'product' => [
                'points' => $points, 'period' => $from->toDateString(),
                'current' => $ranks->firstWhere('id', $current?->id),
                'next' => $ranks->first(fn (array $rank) => $rank['position'] > ($current?->order ?? 0)),
                'ranks' => $ranks->all(),
            ],
            'service' => $service,
        ], 'Progreso de rangos.');
    }

    public function members(Request $request)
    {
        abort_unless($request->user()->is_admin, 403);
        $input = $request->validate(['search' => ['required', 'string', 'min:2', 'max:80']]);
        $search = trim($input['search']);
        $members = User::where('is_admin', false)
            ->where(fn ($query) => $query->where('uuid', 'like', $search.'%')->orWhere('name', 'like', '%'.$search.'%'))
            ->orderBy('name')->limit(20)->get(['id', 'uuid', 'name']);

        return $this->sendResponse($members, 'Socios encontrados.');
    }
}
