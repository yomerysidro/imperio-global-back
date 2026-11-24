<?php

namespace App\Services\Core;

use App\Models\User;

class NetworkActivityMetrics
{
    public function __construct(
        private NetworkTreeService $tree,
        private ActivationService $activation,
    ) {}

    /** Counts are based on the current sponsor graph; the root is excluded. */
    public function forUser(
        User $root,
        ?array $descendantCodes = null,
        ?array $directCodes = null,
        array $knownActivity = []
    ): array
    {
        $codes = $descendantCodes ?? $this->tree->descendantCodes($root->uuid);
        $directCodes ??= $this->tree->directUserCodes($root->uuid);
        $directLookup = array_fill_keys(array_map('strtoupper', $directCodes), true);
        $directActive = 0;
        $networkActive = 0;
        $pending = array_filter($codes, fn ($code) => !array_key_exists(strtoupper($code), $knownActivity));
        foreach (array_chunk($pending, 500) as $batch) {
            $members = User::whereIn('uuid', $batch)->get(['id', 'uuid', 'is_admin'])
                ->keyBy(fn (User $member) => strtoupper($member->uuid));
            $activity = $this->activation->activityForUsers($members);
            foreach ($batch as $code) {
                $key = strtoupper($code);
                $member = $members->get($key);
                $knownActivity[$key] = $member ? $activity[$member->id] : false;
            }
        }
        foreach ($codes as $code) {
            $key = strtoupper($code);
            if ($knownActivity[$key]) {
                $networkActive++;
                $directActive += (int) isset($directLookup[$key]);
            }
        }

        return [
            'total_network' => count($codes),
            'total_directs' => count($directCodes),
            'total_active' => $networkActive,
            'total_directs_active' => $directActive,
            'source' => 'sponsor_relations',
        ];
    }
}
