<?php

namespace App\Services\Core;

use App\Models\User;
use App\Models\PaymentOrderPoint;
use App\Models\GuestsTokenUser;
use App\Models\PaymentLog;
use App\Models\SponsorRelation;
use App\Models\PaymentProductOrder;
use Carbon\Carbon;

class NetworkTreeService
{
    public function directUserCodes(string $userCode): array
    {
        $relations = SponsorRelation::where('sponsor_code', $userCode)
            ->where('state', true)
            ->pluck('user_code')
            ->filter(fn ($code) => strcasecmp($code, $userCode) !== 0)
            ->unique(fn ($code) => strtoupper($code))
            ->values()
            ->toArray();

        if ($relations) {
            return $relations;
        }

        $purchases = PaymentOrderPoint::where('sponsor_code', $userCode)
            ->where('type', PaymentOrderPoint::COMPRA)
            ->pluck('user_code');
        $guests = GuestsTokenUser::where('sponsor_user_code', $userCode)
            ->where('state', true)
            ->pluck('guest_user_code');

        return $purchases->merge($guests)
            ->filter(fn ($code) => strcasecmp($code, $userCode) !== 0)
            ->unique(fn ($code) => strtoupper($code))
            ->values()
            ->toArray();
    }

    public function sponsorCode(string $userCode): ?string
    {
        $relation = SponsorRelation::where('user_code', $userCode)
            ->where('state', true)
            ->first();

        if ($relation && strcasecmp($relation->sponsor_code, $userCode) !== 0) {
            return $relation->sponsor_code;
        }

        $legacy = PaymentOrderPoint::where('user_code', $userCode)
            ->where('type', PaymentOrderPoint::COMPRA)
            ->whereColumn('user_code', '!=', 'sponsor_code')
            ->orderBy('created_at')
            ->first();

        return $legacy?->sponsor_code;
    }

    public function getAllNetworkUsers($userCode, &$visited = [])
    {
        $normalized = strtoupper($userCode);
        if (in_array($normalized, $visited, true)) {
            return [];
        }
        $visited[] = $normalized;
        $users     = [$userCode];

        $allChildren = $this->directUserCodes($userCode);

        foreach ($allChildren as $child) {
            $childUsers = $this->getAllNetworkUsers($child, $visited);
            $users      = array_merge($users, $childUsers);
        }

        return array_unique($users);
    }

    public function countTotalNetworkRecursive($userCode, &$visited = [])
    {
        $normalized = strtoupper($userCode);
        if (in_array($normalized, $visited, true)) {
            return 0;
        }
        $visited[] = $normalized;

        // "Personas en red" representa toda la genealogía permanente. El
        // estado de compra del mes pertenece al contador de activos y no debe
        // cortar ramas completas ni ocultar usuarios inactivos.
        $count = 0;
        foreach ($this->directUserCodes($userCode) as $child) {
            $count++;
            $count += $this->countTotalNetworkRecursive($child, $visited);
        }

        return $count;
    }

    public function buildDescendantTree($userCode, $currentLevel = 0, $maxLevel = 15, &$visited = [])
    {
        $normalized = strtoupper($userCode);
        if ($currentLevel >= $maxLevel || isset($visited[$normalized])) {
            return null;
        }
        $visited[$normalized] = true;

        $user = User::where('uuid', $userCode)->first();

        $node = [
            'user_code'      => $userCode,
            'user_name'      => $user ? $user->name : 'Usuario no encontrado',
            'email'          => $user ? $user->email : null,
            'level'          => $currentLevel,
            'active'         => $user ? $user->active : false,
            'children' => [],
            'total_children' => 0
        ];

        foreach ($this->directUserCodes($userCode) as $childCode) {
            $childNode = $this->buildDescendantTree($childCode, $currentLevel + 1, $maxLevel, $visited);
            if ($childNode) {
                $childNode['source'] = 'sponsor_relation';
                $node['children'][]  = $childNode;
            }
        }

        $node['total_children'] = count($node['children']);
        return $node;
    }

    public function countNodes($tree)
    {
        if (!$tree) {
            return 0;
        }
        $count = 1;
        if (isset($tree['children']) && is_array($tree['children'])) {
            foreach ($tree['children'] as $child) {
                $count += $this->countNodes($child);
            }
        }
        return $count;
    }

    public function loopTree(array $a_paymentOrderPoint, string $userCode)
    {
        $visited = collect($a_paymentOrderPoint)
            ->pluck('user_code')
            ->map(fn ($code) => strtoupper($code))
            ->all();

        while (strtoupper($userCode) !== 'DOSB' && !in_array(strtoupper($userCode), $visited, true)) {
            $visited[] = strtoupper($userCode);
            $sponsorCode = $this->sponsorCode($userCode);
            if (!$sponsorCode || strcasecmp($sponsorCode, $userCode) === 0) break;

            $a_paymentOrderPoint[] = (object) [
                'user_code' => $userCode,
                'sponsor_code' => $sponsorCode,
            ];
            $userCode = $sponsorCode;
        }

        return $a_paymentOrderPoint;
    }
}
