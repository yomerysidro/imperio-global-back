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
    public function forUser(User $root): array
    {
        $codes = array_values(array_filter(
            $this->tree->getAllNetworkUsers($root->uuid),
            fn ($code) => strcasecmp((string) $code, (string) $root->uuid) !== 0
        ));
        $directCodes = $this->tree->directUserCodes($root->uuid);
        $members = User::whereIn('uuid', $codes)->get()->keyBy(fn (User $user) => strtoupper($user->uuid));
        $directActive = 0;
        $networkActive = 0;
        foreach ($codes as $code) {
            $member = $members->get(strtoupper((string) $code));
            if (!$member || !$this->activation->isActive($member)) continue;
            $networkActive++;
            if (in_array(strtoupper((string) $code), array_map('strtoupper', $directCodes), true)) {
                $directActive++;
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
