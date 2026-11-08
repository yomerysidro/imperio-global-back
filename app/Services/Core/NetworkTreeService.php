<?php

namespace App\Services\Core;

use App\Models\User;
use App\Models\PaymentOrderPoint;
use App\Models\GuestsTokenUser;
use App\Models\PaymentLog;
use App\Models\SponsorRelation;
use App\Models\PaymentProductOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NetworkTreeService
{
    public function rootCode(): string
    {
        return app(CorporateRootService::class)->uuid();
    }

    public function directUserCodes(string $userCode): array
    {
        $relations = SponsorRelation::where('sponsor_code', $userCode)
            ->where('state', true)
            ->pluck('user_code')
            ->filter(fn ($code) => strcasecmp($code, $userCode) !== 0)
            ->unique(fn ($code) => strtoupper($code))
            ->values()
            ->toArray();

        return $relations;
    }

    public function sponsorCode(string $userCode, bool $forUpdate = false): ?string
    {
        $query = SponsorRelation::where('user_code', $userCode)->where('state', true);
        if ($forUpdate) $query->lockForUpdate();
        return $query->first()?->sponsor_code;
    }
    /**
     * Serialize mutations that can change the genealogy or calculate an
     * ancestor chain against it. Call only inside an active InnoDB transaction.
     */
    public function lockRootForMutation(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('La genealogía debe bloquearse dentro de una transacción.');
        }

        app(CorporateRootService::class)->user(true);
    }

    /**
     * Load a single consistent view of the descendant tree using the same
     * active sponsor_relations rules as directUserCodes().
     */
    public function snapshotFromRoot(?string $rootCode = null): array
    {
        $rootCode ??= $this->rootCode();
        $users = User::query()->get(['id', 'uuid', 'name', 'is_admin']);
        $usersByCode = [];
        $duplicateUserCodes = [];
        foreach ($users as $user) {
            $code = strtoupper((string) $user->uuid);
            if (isset($usersByCode[$code])) $duplicateUserCodes[$code] = true;
            $usersByCode[$code] = $user;
        }

        $relationChildren = [];
        $relationParent = [];
        foreach (SponsorRelation::where('state', true)->get([
            'user_code', 'sponsor_code', 'source',
        ]) as $relation) {
            $child = strtoupper((string) $relation->user_code);
            $parent = strtoupper((string) $relation->sponsor_code);
            $relationChildren[$parent][$child] = [
                'child_code' => $relation->user_code,
                'source' => $relation->source,
            ];
            $relationParent[$child] = $parent;
        }

        $integrity = [
            'duplicate_user_codes' => array_keys($duplicateUserCodes),
            'orphan_relations' => [],
            'cycles' => [],
            'multiple_parents' => [],
            'legacy_parent_mismatches' => [],
            'unconnected_users' => [],
        ];

        foreach (SponsorRelation::where('state', true)->get(['user_code', 'sponsor_code']) as $relation) {
            $child = strtoupper((string) $relation->user_code);
            $parent = strtoupper((string) $relation->sponsor_code);
            if (!isset($usersByCode[$child]) || !isset($usersByCode[$parent])) {
                $integrity['orphan_relations'][] = [
                    'user_code' => $relation->user_code,
                    'sponsor_code' => $relation->sponsor_code,
                ];
            }
        }

        $childrenFor = fn (string $parent): array => array_values($relationChildren[$parent] ?? []);

        $rootKey = strtoupper($rootCode);
        $root = $usersByCode[$rootKey] ?? null;
        if (!$root) {
            return [
                'root_code' => $rootCode,
                'nodes' => [],
                'edges' => [],
                'integrity' => $integrity + ['missing_root' => true],
            ];
        }

        $nodes = [];
        $edges = [];
        $walk = function (string $code, ?string $parent, int $level, array $path) use (
            &$walk, &$nodes, &$edges, &$integrity, $usersByCode, $childrenFor
        ): void {
            $key = strtoupper($code);
            if (isset($path[$key])) {
                $integrity['cycles'][] = [...array_keys($path), $key];
                return;
            }
            if (isset($nodes[$key])) {
                if (strcasecmp((string) $nodes[$key]['parent_code'], (string) $parent) !== 0) {
                    $integrity['multiple_parents'][$key] = array_values(array_unique(array_filter([
                        $nodes[$key]['parent_code'], $parent,
                    ])));
                }
                return;
            }

            $user = $usersByCode[$key] ?? null;
            if (!$user) {
                $integrity['missing_users'][] = ['user_code' => $code, 'parent_code' => $parent];
                return;
            }

            $nodes[$key] = [
                'user_code' => $user->uuid,
                'user_name' => $user->name,
                'is_admin' => (bool) $user->is_admin,
                'parent_code' => $parent,
                'level' => $level,
                'children' => [],
            ];
            $path[$key] = true;

            foreach ($childrenFor($key) as $edge) {
                $child = (string) $edge['child_code'];
                $childKey = strtoupper($child);
                $edges[] = [
                    'user_code' => $child,
                    'sponsor_code' => $user->uuid,
                    'source' => $edge['source'],
                    'level' => $level + 1,
                ];
                $nodes[$key]['children'][] = $child;
                $walk($child, (string) $user->uuid, $level + 1, $path);
            }
        };

        $walk($root->uuid, null, 0, []);

        // A rooted snapshot must account for every persisted account. Keeping
        // out-of-network users explicit prevents a branch move from being
        // certified while members are already disconnected elsewhere.
        foreach ($usersByCode as $code => $user) {
            if (!isset($nodes[$code])) {
                $integrity['unconnected_users'][] = [
                    'user_code' => $user->uuid,
                    'user_name' => $user->name,
                ];
            }
        }

        // Detect cycles in active sponsor relations even when the cycle is
        // disconnected from corporativa configurada and therefore was not visited by the walk.
        $knownCycles = [];
        foreach (array_keys($relationParent) as $start) {
            $path = [];
            $cursor = $start;
            while (isset($relationParent[$cursor])) {
                if (isset($path[$cursor])) {
                    $cycle = array_slice(array_keys($path), $path[$cursor]);
                    $signatureParts = $cycle;
                    sort($signatureParts);
                    $signature = implode(':', $signatureParts);
                    if (!isset($knownCycles[$signature])) {
                        $knownCycles[$signature] = true;
                        $integrity['cycles'][] = $cycle;
                    }
                    break;
                }
                $path[$cursor] = count($path);
                $cursor = $relationParent[$cursor];
            }
        }

        $integrity['multiple_parents'] = array_map(
            fn ($code, $parents) => ['user_code' => $code, 'sponsors' => $parents],
            array_keys($integrity['multiple_parents']),
            array_values($integrity['multiple_parents'])
        );

        return [
            'root_code' => $root->uuid,
            'nodes' => $nodes,
            'edges' => $edges,
            'integrity' => $integrity,
        ];
    }

    /**
     * Determine whether a user belongs anywhere below the given sponsor.
     * Walking upwards avoids loading the sponsor's complete descendant tree.
     */
    public function belongsToNetwork(string $sponsorCode, string $targetCode): bool
    {
        if (strcasecmp($sponsorCode, $targetCode) === 0) {
            return true;
        }

        $visited = [];
        $currentCode = $targetCode;

        while ($currentCode !== '') {
            $normalizedCode = strtoupper($currentCode);
            if (isset($visited[$normalizedCode])) {
                return false;
            }

            $visited[$normalizedCode] = true;
            $parentCode = $this->sponsorCode($currentCode);

            if (!$parentCode || strcasecmp($parentCode, $currentCode) === 0) {
                return false;
            }

            if (strcasecmp($parentCode, $sponsorCode) === 0) {
                return true;
            }

            $currentCode = $parentCode;
        }

        return false;
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

    public function loopTree(array $a_paymentOrderPoint, string $userCode, bool $forUpdate = false)
    {
        $visited = collect($a_paymentOrderPoint)
            ->pluck('user_code')
            ->map(fn ($code) => strtoupper($code))
            ->all();

        $rootCode = strtoupper($this->rootCode());
        while (strtoupper($userCode) !== $rootCode && !in_array(strtoupper($userCode), $visited, true)) {
            $visited[] = strtoupper($userCode);
            $sponsorCode = $this->sponsorCode($userCode, $forUpdate);
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
