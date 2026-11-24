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
    public function forUser(User $root, ?array $descendantCodes = null): array
    {
        $codes = $descendantCodes ?? $this->tree->descendantCodes($root->uuid);
        $directCodes = $this->tree->directUserCodes($root->uuid);
        $directLookup = array_fill_keys(array_map('strtoupper', $directCodes), true);
        $directActive = 0;
        $networkActive = 0;
        foreach (array_chunk($codes, 500) as $batch) {
            $members = User::whereIn('uuid', $batch)->get(['id', 'uuid', 'is_admin'])
                ->keyBy(fn (User $member) => strtoupper($member->uuid));
            foreach ($batch as $code) {
                $key = strtoupper($code);
                $member = $members->get($key);
                if (!$member || !$this->activation->isActive($member)) continue;
                $networkActive++;
                if (isset($directLookup[$key])) {
                    $directActive++;
                }
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
