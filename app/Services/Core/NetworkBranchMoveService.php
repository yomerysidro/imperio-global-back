<?php

namespace App\Services\Core;

use App\Models\PaymentLog;
use App\Models\PaymentOrder;
use App\Models\PaymentOrderPoint;
use App\Models\PaymentProductOrder;
use App\Models\SponsorRelation;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class NetworkBranchMoveService
{
    public function __construct(private NetworkTreeService $tree) {}

    public function preview(string $userCode, string $newSponsorCode, int $adminId): array
    {
        $snapshot = $this->tree->snapshotFromRoot();
        $plan = $this->buildPlan($snapshot, $userCode, $newSponsorCode);
        $pending = $this->pendingOrders($plan['branch_codes']);
        $plan['pending_orders'] = $pending;
        $plan['valid'] = $plan['valid'] && !$pending['blocked'];
        $plan['warnings'] = [
            'Las comisiones históricas y pagadas no se modifican.',
            'Las comisiones futuras usan la genealogía vigente del motor actual.',
            'Los rangos y bonos pueden verse afectados por la nueva red; no se recalculan en esta operación.',
        ];
        if ($pending['blocked']) $plan['errors'][] = 'Hay operaciones pendientes con comisiones ya iniciadas; requieren conciliacion financiera antes del movimiento.';
        if ($pending['blocked']) $plan['warnings'][] = 'Hay comisiones en pedidos todavia pendientes; se bloquea para evitar mezclar genealogias.';

        $operationId = (string) Str::uuid();
        $payload = [
            'operation_id' => $operationId,
            'admin_id' => $adminId,
            'user_code' => $plan['user_code'],
            'old_sponsor_code' => $plan['old_sponsor_code'],
            'new_sponsor_code' => $plan['new_sponsor_code'],
            'before_hash' => $this->structureHash($plan['branch_codes'], $snapshot['edges']),
            'expires_at' => now()->addMinutes(10)->timestamp,
        ];
        $plan['operation_id'] = $operationId;
        $plan['preview_token'] = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        $plan['expires_at'] = date(DATE_ATOM, $payload['expires_at']);

        return $plan;
    }

    public function confirm(string $token, int $adminId): array
    {
        try {
            $claims = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('La simulación no es válida o expiró.');
        }
        if (($claims['admin_id'] ?? null) !== $adminId || ($claims['expires_at'] ?? 0) < now()->timestamp) {
            throw new RuntimeException('La simulación no corresponde al administrador o expiró.');
        }

        return DB::transaction(function () use ($claims, $adminId) {
            $this->tree->lockRootForMutation();
            if (!DB::getSchemaBuilder()->hasTable('network_move_audits')) {
                throw new RuntimeException('No existe la tabla de auditoría; no se realizó ningún cambio.');
            }
            $snapshot = $this->tree->snapshotFromRoot();
            $plan = $this->buildPlan($snapshot, $claims['user_code'], $claims['new_sponsor_code']);
            if (!$plan['valid'] || $plan['old_sponsor_code'] !== $claims['old_sponsor_code']) {
                throw new RuntimeException('La genealogía cambió o la simulación ya no es válida.');
            }
            $lockedCodes = array_values(array_unique(array_merge(
                $plan['branch_codes'], [$plan['new_sponsor_code']]
            )));
            $lockedUsers = User::whereIn('uuid', $lockedCodes)->orderBy('id')->lockForUpdate()->get(['id', 'uuid']);
            if ($lockedUsers->count() !== count($lockedCodes)) {
                throw new RuntimeException('Cambió la lista de socios de la rama; se canceló el movimiento.');
            }
            // Re-read after acquiring row locks so the preview is compared to
            // the exact graph protected for the rest of this transaction.
            $snapshot = $this->tree->snapshotFromRoot();
            $plan = $this->buildPlan($snapshot, $claims['user_code'], $claims['new_sponsor_code']);
            if (!$plan['valid'] || $plan['old_sponsor_code'] !== $claims['old_sponsor_code']) {
                throw new RuntimeException('La genealogía cambió durante la validación.');
            }
            $hash = $this->structureHash($plan['branch_codes'], $snapshot['edges']);
            if (!hash_equals($claims['before_hash'], $hash)) {
                throw new RuntimeException('La estructura de la rama cambió desde la simulación.');
            }
            $pending = $this->pendingOrders($plan['branch_codes'], true);
            if ($pending['blocked']) throw new RuntimeException('Hay registros de puntos en pedidos pendientes; concilie la distribucion antes del movimiento.');

            $rootCode = $plan['user_code'];
            $relation = SponsorRelation::where('user_code', $rootCode)->lockForUpdate()->first();
            if ($relation) {
                $relation->sponsor_code = $plan['new_sponsor_code'];
                $relation->source = 'admin_branch_move';
                $relation->state = true;
                $relation->save();
            } else {
                SponsorRelation::create([
                    'user_code' => $rootCode,
                    'sponsor_code' => $plan['new_sponsor_code'],
                    'source' => 'admin_branch_move',
                    'state' => true,
                ]);
            }

            $after = $this->tree->snapshotFromRoot();
            $afterIntegrityErrors = $this->relevantIntegrityErrors(
                $after,
                array_merge(
                    $plan['branch_codes'],
                    array_column($this->routeFromNode($rootCode, $after['nodes']), 'user_code'),
                    array_column($this->routeFromNode($plan['new_sponsor_code'], $after['nodes']), 'user_code')
                )
            );
            $beforeUnconnected = $this->normalizedList($snapshot['integrity']['unconnected_users'] ?? []);
            $afterUnconnected = $this->normalizedList($after['integrity']['unconnected_users'] ?? []);
            if ($afterIntegrityErrors || $beforeUnconnected !== $afterUnconnected) {
                throw new RuntimeException('La comprobación posterior detectó una inconsistencia; se revirtió el movimiento.');
            }
            $afterCodes = array_fill_keys(array_map('strtoupper', array_column($after['nodes'], 'user_code')), true);
            $sameMembers = count($plan['branch_codes']) === count(array_intersect_key(
                array_fill_keys(array_map('strtoupper', $plan['branch_codes']), true),
                $afterCodes
            ));
            $afterBranch = $this->branchCodes($after, $rootCode);
            $sameMembers = $sameMembers && count($afterBranch) === count($plan['branch_codes'])
                && !array_diff(array_map('strtoupper', $plan['branch_codes']), array_map('strtoupper', $afterBranch));
            $expectedLevels = [];
            foreach ($plan['proposed_nodes'] as $proposedNode) {
                $expectedLevels[strtoupper($proposedNode['user_code'])] = (int) $proposedNode['level'];
            }
            $levelsMatch = true;
            foreach ($expectedLevels as $code => $expectedLevel) {
                if ((int) ($after['nodes'][$code]['level'] ?? -1) !== $expectedLevel) {
                    $levelsMatch = false;
                    break;
                }
            }
            $newRootParent = $after['nodes'][strtoupper($rootCode)]['parent_code'] ?? null;
            if (!$sameMembers || !$levelsMatch
                || strcasecmp((string) $newRootParent, $plan['new_sponsor_code']) !== 0) {
                throw new RuntimeException('La comprobación posterior detectó una inconsistencia; se revirtió el movimiento.');
            }
            $this->assertInternalEdgesUnchanged($plan['branch_codes'], $snapshot['edges'], $after['edges']);

            $afterHash = $this->structureHash($plan['branch_codes'], $after['edges']);
            $confirmedMembers = array_map(function (string $code) use ($after) {
                $member = $after['nodes'][strtoupper($code)];
                return [
                    'user_code' => $member['user_code'],
                    'sponsor_code' => $member['parent_code'],
                    'level' => $member['level'],
                ];
            }, $afterBranch);
            DB::table('network_move_audits')->insert([
                'operation_id' => $claims['operation_id'],
                'admin_user_id' => $adminId,
                'user_code' => $rootCode,
                'old_sponsor_code' => $plan['old_sponsor_code'],
                'new_sponsor_code' => $plan['new_sponsor_code'],
                'descendant_count' => count($plan['branch_codes']) - 1,
                'total_affected' => count($plan['branch_codes']),
                'structure_before_hash' => $hash,
                'structure_after_hash' => $afterHash,
                'status' => 'confirmed',
                'details' => json_encode([
                    'affected_codes' => $plan['branch_codes'],
                    'before_relationships' => $snapshot['edges'],
                    'after_relationships' => $after['edges'],
                    'unconnected_users_before' => count($snapshot['integrity']['unconnected_users'] ?? []),
                    'unconnected_users_after' => count($after['integrity']['unconnected_users'] ?? []),
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'operation_id' => $claims['operation_id'], 'status' => 'confirmed',
                'user_code' => $rootCode, 'old_sponsor_code' => $plan['old_sponsor_code'],
                'new_sponsor_code' => $plan['new_sponsor_code'],
                'descendant_count' => count($plan['branch_codes']) - 1,
                'total_affected' => count($plan['branch_codes']),
                'structure_after_hash' => $afterHash,
                'genealogy' => $confirmedMembers,
                'route_after' => $plan['route_after'],
                'pending_orders' => $pending,
                'unrelated_integrity_warnings' => [
                    'unconnected_users_count' => count($snapshot['integrity']['unconnected_users'] ?? []),
                ],
            ];
        }, 3);
    }

    /** Execute an administrator's one-step move request without exposing a simulation token. */
    public function move(string $userCode, string $newSponsorCode, int $adminId): array
    {
        $plan = $this->preview($userCode, $newSponsorCode, $adminId);
        if (!$plan['valid']) {
            throw new RuntimeException(implode(' ', $plan['errors'] ?? ['El movimiento no es elegible.']));
        }

        // confirm() re-reads and locks the complete tree, compares the signed
        // snapshot and performs the mutation plus audit in one transaction.
        return $this->confirm($plan['preview_token'], $adminId);
    }

    public function previewBatch(array $moves, int $adminId): array
    {
        $snapshot = $this->tree->snapshotFromRoot();
        $plan = $this->buildBatchPlan($snapshot, $moves);
        $pending = $this->pendingOrders($plan['affected_codes']);
        $plan['pending_orders'] = $pending;
        $plan['valid'] = $plan['valid'] && !$pending['blocked'];
        if ($pending['blocked']) $plan['errors'][] = 'Hay operaciones pendientes con comisiones ya iniciadas; requieren conciliacion financiera antes del movimiento.';
        if ($pending['blocked']) $plan['warnings'][] = 'Hay comisiones en pedidos todavia pendientes; se bloquea para evitar mezclar genealogias.';
        $plan['warnings'] = [
            'Solo se modificarán las relaciones superiores indicadas en moves.',
            'Las relaciones internas no indicadas se conservarán.',
            'No se recalculan comisiones ni rangos durante la simulación.',
            'Las comisiones históricas no se modifican.',
        ];
        $operationId = (string) Str::uuid();
        $payload = [
            'operation_id' => $operationId,
            'admin_id' => $adminId,
            'moves' => $plan['moves'],
            'before_hash' => $this->structureHash(array_column($snapshot['nodes'], 'user_code'), $snapshot['edges']),
            'expires_at' => now()->addMinutes(10)->timestamp,
        ];
        $plan['operation_id'] = $operationId;
        $plan['preview_token'] = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        $plan['expires_at'] = date(DATE_ATOM, $payload['expires_at']);
        return $plan;
    }

    public function confirmBatch(string $token, int $adminId): array
    {
        try {
            $claims = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('La simulación no es válida o expiró.');
        }
        if (($claims['admin_id'] ?? null) !== $adminId || ($claims['expires_at'] ?? 0) < now()->timestamp) {
            throw new RuntimeException('La simulación no corresponde al administrador o expiró.');
        }

        return DB::transaction(function () use ($claims, $adminId) {
            $this->tree->lockRootForMutation();
            if (!DB::getSchemaBuilder()->hasTable('network_move_audits')) {
                throw new RuntimeException('No existe la tabla de auditoría; no se realizó ningún cambio.');
            }
            $snapshot = $this->tree->snapshotFromRoot();
            $plan = $this->buildBatchPlan($snapshot, $claims['moves']);
            if (!$plan['valid']) throw new RuntimeException('El mapa ya no es válido: '.implode(' ', $plan['errors']));

            $lockCodes = array_values(array_unique(array_merge($plan['affected_codes'], array_values($plan['moves']))));
            $lockedUsers = User::whereIn('uuid', $lockCodes)->orderBy('id')->lockForUpdate()->get(['id', 'uuid']);
            if ($lockedUsers->count() !== count($lockCodes)) throw new RuntimeException('Cambió el conjunto de socios; vuelva a simular.');

            $snapshot = $this->tree->snapshotFromRoot();
            $plan = $this->buildBatchPlan($snapshot, $claims['moves']);
            if (!$plan['valid']) throw new RuntimeException('El mapa dejó de ser válido durante la confirmación.');
            $beforeHash = $this->structureHash(array_column($snapshot['nodes'], 'user_code'), $snapshot['edges']);
            if (!hash_equals($claims['before_hash'], $beforeHash)) throw new RuntimeException('La genealogía cambió desde la simulación.');
            $pending = $this->pendingOrders($plan['affected_codes'], true);
            if ($pending['blocked']) throw new RuntimeException('Hay registros de puntos en pedidos pendientes dentro de las ramas afectadas.');

            foreach ($plan['moves'] as $userCode => $newSponsorCode) {
                $relation = SponsorRelation::where('user_code', $userCode)->lockForUpdate()->first();
                if ($relation) {
                    $relation->sponsor_code = $newSponsorCode;
                    $relation->source = 'admin_branch_move';
                    $relation->state = true;
                    $relation->save();
                } else {
                    SponsorRelation::create([
                        'user_code' => $userCode,
                        'sponsor_code' => $newSponsorCode,
                        'source' => 'admin_branch_move',
                        'state' => true,
                    ]);
                }
            }

            $after = $this->tree->snapshotFromRoot();
            if ($this->parentMap($after) !== $plan['proposed_parents']) {
                throw new RuntimeException('La estructura resultante difiere de la simulación; se revirtió el lote.');
            }
            $afterHash = $this->structureHash(array_column($after['nodes'], 'user_code'), $after['edges']);
            foreach ($plan['moves'] as $userCode => $newSponsorCode) {
                $branch = $plan['branches_by_root'][$userCode] ?? [$userCode];
                DB::table('network_move_audits')->insert([
                    'operation_id' => $claims['operation_id'],
                    'admin_user_id' => $adminId,
                    'user_code' => $userCode,
                    'old_sponsor_code' => $plan['old_parents'][$strtoupper($userCode)],
                    'new_sponsor_code' => $newSponsorCode,
                    'descendant_count' => max(count($branch) - 1, 0),
                    'total_affected' => count($plan['affected_codes']),
                    'structure_before_hash' => $beforeHash,
                    'structure_after_hash' => $afterHash,
                    'status' => 'confirmed',
                    'details' => json_encode([
                        'moves' => $plan['moves'],
                        'affected_codes' => $plan['affected_codes'],
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return [
                'operation_id' => $claims['operation_id'], 'status' => 'confirmed',
                'moves' => $plan['moves'], 'total_affected' => count($plan['affected_codes']),
                'descendant_count' => max(count($plan['affected_codes']) - count($plan['moves']), 0),
                'structure_before_hash' => $beforeHash, 'structure_after_hash' => $afterHash,
            ];
        }, 3);
    }

    private function buildBatchPlan(array $snapshot, array $requestedMoves): array
    {
        $nodes = $snapshot['nodes'];
        $errors = [];
        $integrity = $snapshot['integrity'];
        if (!empty($integrity['missing_root']) || !empty($integrity['duplicate_user_codes'])
            || !empty($integrity['orphan_relations']) || !empty($integrity['cycles'])
            || !empty($integrity['multiple_parents']) || !empty($integrity['missing_users'])
            || !empty($integrity['unconnected_users'])) {
            $errors[] = 'El árbol actual contiene inconsistencias.';
        }
        $moves = [];
        foreach ($requestedMoves as $userCode => $newSponsorCode) {
            $userKey = strtoupper(trim((string) $userCode));
            $sponsorKey = strtoupper(trim((string) $newSponsorCode));
            if (!isset($nodes[$userKey], $nodes[$sponsorKey])) {
                $errors[] = "Socio o patrocinador inexistente en el árbol: {$userCode} → {$newSponsorCode}.";
                continue;
            }
            if (app(CorporateRootService::class)->isRootCode($userKey) || !empty($nodes[$userKey]['is_admin'])) {
                $errors[] = "La cuenta protegida {$userCode} no se puede mover.";
                continue;
            }
            $moves[$nodes[$userKey]['user_code']] = $nodes[$sponsorKey]['user_code'];
        }
        if (!$moves) $errors[] = 'El mapa de movimientos está vacío o no contiene relaciones válidas.';

        $parents = $this->parentMap($snapshot);
        $oldParents = $parents;
        foreach ($moves as $userCode => $sponsorCode) {
            $parents[strtoupper($userCode)] = $sponsorCode;
        }
        $rootKey = strtoupper($this->tree->rootCode());
        foreach (array_keys($parents) as $key) {
            $cursor = $key;
            $seen = [];
            while ($cursor !== $rootKey && isset($parents[$cursor])) {
                if (isset($seen[$cursor])) {
                    $errors[] = "El mapa crea un ciclo que incluye {$cursor}.";
                    break;
                }
                $seen[$cursor] = true;
                $parent = $parents[$cursor];
                if (!$parent || !isset($nodes[strtoupper($parent)])) {
                    $errors[] = "El socio {$cursor} quedaría desconectado de la raíz configurada.";
                    break;
                }
                $cursor = strtoupper($parent);
            }
        }

        $branchesByRoot = [];
        $affected = [];
        foreach ($moves as $rootCode => $_newSponsor) {
            $rootKey = strtoupper($rootCode);
            $branch = [];
            $walk = function (string $key) use (&$walk, &$branch, $nodes): void {
                if (isset($branch[$key]) || !isset($nodes[$key])) return;
                $branch[$key] = true;
                if (!empty($nodes[$key]['is_admin'])) return;
                foreach ($nodes[$key]['children'] as $child) $walk(strtoupper($child));
            };
            $walk($rootKey);
            foreach (array_keys($branch) as $memberKey) {
                if (!empty($nodes[$memberKey]['is_admin'])) $errors[] = 'Una rama afectada contiene una cuenta administrativa.';
            }
            $branchesByRoot[$rootCode] = array_map(fn ($key) => $nodes[$key]['user_code'], array_keys($branch));
            $affected += $branch;
        }
        $affectedCodes = array_map(fn ($key) => $nodes[$key]['user_code'], array_keys($affected));

        $levels = [];
        foreach (array_keys($parents) as $key) {
            $depth = 0;
            $cursor = $key;
            $seen = [];
            while ($cursor !== $rootKey && isset($parents[$cursor]) && !isset($seen[$cursor])) {
                $seen[$cursor] = true;
                $cursor = strtoupper((string) $parents[$cursor]);
                $depth++;
            }
            $levels[$key] = $depth;
        }
        $details = [];
        foreach ($affectedCodes as $code) {
            $key = strtoupper($code);
            $details[] = [
                'user_code' => $code,
                'name' => $nodes[$key]['user_name'],
                'sponsor_before' => $oldParents[$key],
                'sponsor_after' => $parents[$key],
                'level_before' => $nodes[$key]['level'],
                'level_after' => $levels[$key],
                'route_before' => $this->routeFromNode($code, $nodes),
                'route_after' => $this->routeFromParents($code, $nodes, $parents),
            ];
        }
        $proposedParents = $parents;
        ksort($proposedParents);
        return [
            'valid' => !$errors, 'errors' => array_values(array_unique($errors)),
            'moves' => $moves, 'old_parents' => $oldParents,
            'proposed_parents' => $proposedParents,
            'affected_codes' => $affectedCodes,
            'descendant_count' => max(count($affectedCodes) - count($moves), 0),
            'total_affected' => count($affectedCodes),
            'branches_by_root' => $branchesByRoot,
            'affected_members' => $details,
            'relationships_before' => $snapshot['edges'],
            'relationships_after' => array_map(fn ($edge) => [
                ...$edge, 'sponsor_code' => $parents[strtoupper($edge['user_code'])] ?? $edge['sponsor_code'],
                'level' => $levels[strtoupper($edge['user_code'])] ?? $edge['level'],
            ], $snapshot['edges']),
            'overlapping_branches' => $this->overlappingBranches($branchesByRoot),
            'integrity' => $integrity,
        ];
    }

    private function parentMap(array $snapshot): array
    {
        $parents = [];
        foreach ($snapshot['nodes'] as $key => $node) $parents[$key] = $node['parent_code'];
        ksort($parents);
        return $parents;
    }

    private function overlappingBranches(array $branches): array
    {
        $owners = [];
        foreach ($branches as $root => $codes) foreach ($codes as $code) $owners[strtoupper($code)][] = $root;
        return array_values(array_map(fn ($code, $roots) => [
            'user_code' => $code, 'move_roots' => array_values(array_unique($roots)),
        ], array_keys(array_filter($owners, fn ($roots) => count($roots) > 1)),
            array_filter($owners, fn ($roots) => count($roots) > 1)));
    }

    private function buildPlan(array $snapshot, string $userCode, string $newSponsorCode): array
    {
        $nodes = $snapshot['nodes'];
        $userKey = strtoupper($userCode);
        $sponsorKey = strtoupper($newSponsorCode);
        $errors = [];
        $integrity = $snapshot['integrity'];
        if (!empty($integrity['missing_root'])) {
            $errors[] = 'El árbol contiene inconsistencias y requiere revisión manual.';
        }
        if (!isset($nodes[$userKey])) $errors[] = 'El socio no pertenece al árbol de la raíz configurada.';
        if (!isset($nodes[$sponsorKey])) $errors[] = 'El nuevo patrocinador no pertenece al árbol de la raíz configurada.';
        if (app(CorporateRootService::class)->isRootCode($userKey)) $errors[] = 'La raíz corporativa no se puede mover.';
        if (!empty($nodes[$userKey]['is_admin'])) $errors[] = 'Una cuenta administrativa protegida no se puede mover.';

        $branch = [];
        if (isset($nodes[$userKey])) {
            $walk = function (string $key) use (&$walk, &$branch, $nodes): void {
                if (isset($branch[$key]) || !isset($nodes[$key])) return;
                $branch[$key] = true;
                foreach ($nodes[$key]['children'] as $child) $walk(strtoupper($child));
            };
            $walk($userKey);
        }
        if (isset($branch[$sponsorKey])) $errors[] = 'El patrocinador destino está dentro de la rama; el movimiento crearía un ciclo.';
        foreach (array_keys($branch) as $branchKey) {
            if (!empty($nodes[$branchKey]['is_admin'])) {
                $errors[] = 'La rama contiene una cuenta administrativa protegida.';
                break;
            }
        }
        $node = $nodes[$userKey] ?? null;
        $sponsor = $nodes[$sponsorKey] ?? null;
        if ($node && strcasecmp((string) $node['parent_code'], $newSponsorCode) === 0) $errors[] = 'El socio ya tiene ese patrocinador.';

        $relevantCodes = array_merge(
            array_map(fn ($key) => $nodes[$key]['user_code'], array_keys($branch)),
            array_column($this->routeFromNode($userCode, $nodes), 'user_code'),
            array_column($this->routeFromNode($newSponsorCode, $nodes), 'user_code')
        );
        $errors = array_merge($errors, $this->relevantIntegrityErrors($snapshot, $relevantCodes));

        $branchCodes = array_map(fn ($key) => $nodes[$key]['user_code'], array_keys($branch));
        $levelDelta = (($sponsor['level'] ?? 0) + 1) - ($node['level'] ?? 0);
        $oldEdges = array_values(array_filter($snapshot['edges'], fn ($edge) => isset($branch[strtoupper($edge['user_code'])])));
        $proposedEdges = [];
        foreach ($oldEdges as $edge) {
            $isRoot = strcasecmp($edge['user_code'], $node['user_code'] ?? '') === 0;
            $proposedEdges[] = [
                'user_code' => $edge['user_code'],
                'sponsor_code' => $isRoot ? ($sponsor['user_code'] ?? $newSponsorCode) : $edge['sponsor_code'],
                'level' => $edge['level'] + $levelDelta,
            ];
        }
        $current = array_map(fn ($code) => ['user_code' => $code, 'level' => $nodes[strtoupper($code)]['level']], $branchCodes);
        $proposed = array_map(fn ($item) => [
            'user_code' => $item['user_code'],
            'level' => $item['level'] + $levelDelta,
        ], $current);
        $proposedParents = [];
        foreach ($nodes as $key => $item) $proposedParents[$key] = $item['parent_code'];
        if ($node && $sponsor) $proposedParents[$userKey] = $sponsor['user_code'];
        $memberDetails = [];
        foreach ($branchCodes as $code) {
            $key = strtoupper($code);
            $oldParent = $nodes[$key]['parent_code'];
            $newParent = $proposedParents[$key];
            $oldParentNode = $oldParent ? ($nodes[strtoupper($oldParent)] ?? null) : null;
            $newParentNode = $newParent ? ($nodes[strtoupper($newParent)] ?? null) : null;
            $memberDetails[] = [
                'user_code' => $code,
                'name' => $nodes[$key]['user_name'],
                'sponsor_before' => $oldParent,
                'sponsor_before_name' => $oldParentNode['user_name'] ?? null,
                'sponsor_after' => $newParent,
                'sponsor_after_name' => $newParentNode['user_name'] ?? null,
                'level_before' => $nodes[$key]['level'],
                'level_after' => $nodes[$key]['level'] + $levelDelta,
                'route_before' => $this->routeFromNode($code, $nodes),
                'route_after' => $this->routeFromParents($code, $nodes, $proposedParents),
            ];
        }
        $oldSponsorNode = $node && $node['parent_code'] ? ($nodes[strtoupper($node['parent_code'])] ?? null) : null;

        return [
            'valid' => !$errors, 'errors' => $errors,
            'user_code' => $node['user_code'] ?? $userCode,
            'user_name' => $node['user_name'] ?? null,
            'old_sponsor_code' => $node['parent_code'] ?? null,
            'old_sponsor_name' => $oldSponsorNode['user_name'] ?? null,
            'new_sponsor_code' => $sponsor['user_code'] ?? $newSponsorCode,
            'new_sponsor_name' => $sponsor['user_name'] ?? null,
            'descendant_count' => max(0, count($branchCodes) - 1),
            'total_affected' => count($branchCodes),
            'branch_codes' => $branchCodes,
            'affected_members' => $memberDetails,
            'route_before' => $node ? $this->routeFromNode($node['user_code'], $nodes) : [],
            'route_after' => $node ? $this->routeFromParents($node['user_code'], $nodes, $proposedParents) : [],
            'current_nodes' => $current, 'proposed_nodes' => $proposed,
            'relationships_before' => $oldEdges, 'relationships_after' => $proposedEdges,
            'internal_relationships_preserved' => array_values(array_filter($oldEdges, fn ($edge) => isset($branch[strtoupper($edge['sponsor_code'])]))),
            'integrity' => $integrity,
        ];
    }

    private function routeFromNode(string $code, array $nodes): array
    {
        $parents = [];
        foreach ($nodes as $key => $node) $parents[$key] = $node['parent_code'];
        return $this->routeFromParents($code, $nodes, $parents);
    }

    /** Return only integrity defects intersecting the moved branch or its affected ancestor paths. */
    private function relevantIntegrityErrors(array $snapshot, array $relevantCodes): array
    {
        $integrity = $snapshot['integrity'] ?? [];
        $relevant = array_fill_keys(array_map('strtoupper', $relevantCodes), true);
        $errors = [];

        foreach ($integrity['duplicate_user_codes'] ?? [] as $code) {
            if (isset($relevant[strtoupper((string) $code)])) {
                $errors[] = "UUID duplicado en la genealogia afectada: {$code}.";
            }
        }
        foreach ($integrity['orphan_relations'] ?? [] as $relation) {
            $child = strtoupper((string) ($relation['user_code'] ?? ''));
            $parent = strtoupper((string) ($relation['sponsor_code'] ?? ''));
            if (isset($relevant[$child]) || isset($relevant[$parent])) {
                $errors[] = 'Existe una relacion huerfana en la rama o ruta ascendente afectada.';
            }
        }
        foreach ($integrity['cycles'] ?? [] as $cycle) {
            if (array_intersect(array_map('strtoupper', $cycle), array_keys($relevant))) {
                $errors[] = 'Existe un ciclo en la rama o ruta ascendente afectada.';
            }
        }
        foreach ($integrity['multiple_parents'] ?? [] as $issue) {
            $code = is_array($issue) ? ($issue['user_code'] ?? '') : '';
            if (isset($relevant[strtoupper((string) $code)])) {
                $errors[] = "El socio {$code} tiene mas de un patrocinador en la ruta afectada.";
            }
        }
        foreach ($integrity['missing_users'] ?? [] as $issue) {
            $code = strtoupper((string) ($issue['user_code'] ?? ''));
            $parent = strtoupper((string) ($issue['parent_code'] ?? ''));
            if (isset($relevant[$code]) || isset($relevant[$parent])) {
                $errors[] = 'Falta una cuenta en la rama o ruta ascendente afectada.';
            }
        }
        foreach ($integrity['legacy_parent_mismatches'] ?? [] as $issue) {
            $code = strtoupper((string) ($issue['user_code'] ?? ''));
            if (isset($relevant[$code])) {
                $errors[] = 'Una relacion historica contradice la genealogia actual de la rama.';
            }
        }

        return array_values(array_unique($errors));
    }

    private function normalizedList(array $items): array
    {
        $normalized = array_map(static fn ($item) => json_encode($item, JSON_UNESCAPED_UNICODE), $items);
        sort($normalized);
        return $normalized;
    }

    private function routeFromParents(string $code, array $nodes, array $parents): array
    {
        $route = [];
        $seen = [];
        $current = strtoupper($code);
        while (isset($nodes[$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            $route[] = ['user_code' => $nodes[$current]['user_code'], 'name' => $nodes[$current]['user_name']];
            $parent = $parents[$current] ?? null;
            if (!$parent) break;
            $current = strtoupper($parent);
        }
        return $route;
    }

    private function pendingOrders(array $codes, bool $lock = false): array
    {
        $userIds = User::whereIn('uuid', $codes)->pluck('id');
        $usersById = User::whereIn('id', $userIds)->get(['id', 'uuid', 'name'])->keyBy('id');
        $logsQuery = PaymentLog::with(['paymentOrder.pack'])
            ->whereIn('user_id', $userIds)
            ->whereIn('state', [PaymentLog::PENDIENTEPAGO, PaymentLog::PREORDER]);
        if ($lock) $logsQuery->lockForUpdate();
        $logs = $logsQuery->get(['id', 'payment_order_id', 'user_id', 'state', 'confirm', 'created_at']);
        $productsQuery = PaymentProductOrder::with('pack')->whereIn('user_id', $userIds)
            ->whereIn('state', [PaymentProductOrder::PENDIENTEPAGO, PaymentProductOrder::PREORDER]);
        if ($lock) $productsQuery->lockForUpdate();
        $products = $productsQuery->get([
            'id', 'user_id', 'state', 'type', 'created_at', 'amount', 'points', 'pack_id',
        ]);

        $paymentOrderIds = $logs->pluck('payment_order_id')->filter()->unique()->values();
        $paymentPointStats = $paymentOrderIds->isEmpty() ? collect() : DB::table('payment_order_points')
            ->whereIn('payment_order_id', $paymentOrderIds)
            ->selectRaw('payment_order_id, COUNT(*) AS point_entry_count')
            ->selectRaw('SUM(CASE WHEN state = 1 AND type IN (?, ?, ?, ?, ?) THEN 1 ELSE 0 END) AS active_commission_entry_count', [
                PaymentOrderPoint::PATROCINIO, PaymentOrderPoint::PATROCINIO_SERVICIO,
                PaymentOrderPoint::RESIDUAL, PaymentOrderPoint::RESIDUAL_SERVICIO,
                PaymentOrderPoint::INFINITO,
            ])
            ->selectRaw('SUM(CASE WHEN state = 1 AND type IN (?, ?, ?, ?, ?) THEN point ELSE 0 END) AS active_commission_point_total', [
                PaymentOrderPoint::PATROCINIO, PaymentOrderPoint::PATROCINIO_SERVICIO,
                PaymentOrderPoint::RESIDUAL, PaymentOrderPoint::RESIDUAL_SERVICIO,
                PaymentOrderPoint::INFINITO,
            ])
            ->groupBy('payment_order_id')->get()->keyBy('payment_order_id');

        $productOrderIds = $products->pluck('id')->values();
        $productPointStats = $productOrderIds->isEmpty() ? collect() : DB::table('payment_product_order_points')
            ->whereIn('payment_product_order_id', $productOrderIds)
            ->selectRaw('payment_product_order_id, COUNT(*) AS point_entry_count')
            ->selectRaw('SUM(CASE WHEN state = 1 THEN points ELSE 0 END) AS active_point_total')
            ->groupBy('payment_product_order_id')->get()->keyBy('payment_product_order_id');

        $paymentStateLabel = fn (int $state): string => match ($state) {
            PaymentLog::PENDIENTEPAGO => 'PENDIENTE_PAGO',
            PaymentLog::PREORDER => 'PREORDER',
            default => 'UNKNOWN',
        };
        $productStateLabel = fn (int $state): string => match ($state) {
            PaymentProductOrder::PENDIENTEPAGO => 'PENDIENTE_PAGO',
            PaymentProductOrder::PREORDER => 'PREORDER',
            default => 'UNKNOWN',
        };

        $financiallyUnsafe = $logs->contains(fn ($log) => (int) ($paymentPointStats->get($log->payment_order_id)?->point_entry_count ?? 0) > 0)
            || $products->contains(fn ($order) => (int) ($productPointStats->get($order->id)?->point_entry_count ?? 0) > 0);

        return [
            // Pending orders without generated points are informational. Any
            // point entries on an unpaid order indicate partial processing.
            'blocked' => $financiallyUnsafe,
            'financially_unsafe' => $financiallyUnsafe,
            'block_reason' => $financiallyUnsafe ? 'pending_order_has_existing_point_entries' : null,
            'payment_order_count' => $logs->count(),
            'product_order_count' => $products->count(),
            'total_order_count' => $logs->count() + $products->count(),
            'payment_amount_total' => (float) $logs->sum(fn ($log) => (float) ($log->paymentOrder?->amount ?? 0)),
            'product_amount_total' => (float) $products->sum(fn ($order) => (float) $order->amount),
            'product_points_total' => (float) $products->sum(fn ($order) => (float) $order->points),
            'financial_impact_note' => 'Los pedidos pendientes sin puntos generados son informativos y se liquidan con la genealogia vigente al confirmarse el pago. Los pedidos con puntos existentes quedan bloqueados para conciliacion.',
            'payment_orders' => $logs->map(function ($log) use ($usersById, $paymentPointStats, $paymentStateLabel) {
                $order = $log->paymentOrder;
                $owner = $usersById->get($log->user_id);
                $stats = $paymentPointStats->get($log->payment_order_id);
                return [
                    'id' => $log->payment_order_id,
                    'payment_log_id' => $log->id,
                    'owner_code' => $owner?->uuid,
                    'owner_name' => $owner?->name,
                    'state' => (int) $log->state,
                    'state_label' => $paymentStateLabel((int) $log->state),
                    'confirm' => (bool) $log->confirm,
                    'created_at' => $log->created_at,
                    'sponsor_code_snapshot' => $order?->sponsor_code,
                    'type' => $order?->pack?->category ?? 'SERVICE',
                    'amount' => $order?->amount,
                    'currency' => $order?->currency,
                    'pack_id' => $order?->pack_id,
                    'pack_title' => $order?->pack?->title,
                    'pack_category' => $order?->pack?->category,
                    'point_entry_count' => (int) ($stats?->point_entry_count ?? 0),
                    'active_commission_entry_count' => (int) ($stats?->active_commission_entry_count ?? 0),
                    'active_commission_point_total' => (float) ($stats?->active_commission_point_total ?? 0),
                    'commission_generation_status' => (int) ($stats?->active_commission_entry_count ?? 0) > 0
                        ? 'GENERATED'
                        : ((int) ($stats?->point_entry_count ?? 0) > 0 ? 'EXISTING_POINTS_REQUIRES_REVIEW' : 'NOT_GENERATED'),
                ];
            })->values(),
            'product_orders' => $products->map(function ($order) use ($usersById, $productPointStats, $productStateLabel) {
                $owner = $usersById->get($order->user_id);
                $stats = $productPointStats->get($order->id);
                return [
                    'id' => $order->id,
                    'owner_code' => $owner?->uuid,
                    'owner_name' => $owner?->name,
                    'state' => (int) $order->state,
                    'state_label' => $productStateLabel((int) $order->state),
                    'type' => $order->type,
                    'created_at' => $order->created_at,
                    'amount' => $order->amount,
                    'points' => $order->points,
                    'pack_id' => $order->pack_id,
                    'pack_title' => $order->pack?->title,
                    'pack_category' => $order->pack?->category,
                    'point_entry_count' => (int) ($stats?->point_entry_count ?? 0),
                    'active_point_total' => (float) ($stats?->active_point_total ?? 0),
                    'commission_generation_status' => (int) ($stats?->point_entry_count ?? 0) > 0 ? 'EXISTING_POINTS_REQUIRES_REVIEW' : 'NOT_GENERATED',
                ];
            })->values(),
        ];
    }

    private function branchCodes(array $snapshot, string $rootCode): array
    {
        $nodes = $snapshot['nodes'];
        $root = strtoupper($rootCode);
        if (!isset($nodes[$root])) return [];
        $codes = [];
        $walk = function (string $key) use (&$walk, &$codes, $nodes): void {
            if (!isset($nodes[$key]) || isset($codes[$key])) return;
            $codes[$key] = $nodes[$key]['user_code'];
            foreach ($nodes[$key]['children'] as $child) $walk(strtoupper($child));
        };
        $walk($root);
        return array_values($codes);
    }

    private function structureHash(array $branchCodes, array $edges): string
    {
        $codes = array_fill_keys(array_map('strtoupper', $branchCodes), true);
        $subset = array_values(array_filter($edges, fn ($edge) => isset($codes[strtoupper($edge['user_code'])])));
        usort($subset, fn ($a, $b) => strcmp(strtoupper($a['user_code']), strtoupper($b['user_code'])));
        return hash('sha256', json_encode($subset, JSON_THROW_ON_ERROR));
    }

    private function assertInternalEdgesUnchanged(array $branchCodes, array $before, array $after): void
    {
        $codes = array_fill_keys(array_map('strtoupper', $branchCodes), true);
        $pick = fn ($edges) => collect($edges)->filter(fn ($edge) => isset($codes[strtoupper($edge['user_code'])])
            && isset($codes[strtoupper($edge['sponsor_code'])]))
            ->map(fn ($edge) => strtoupper($edge['user_code']).':'.strtoupper($edge['sponsor_code']))->sort()->values()->all();
        if ($pick($before) !== $pick($after)) throw new RuntimeException('Cambió una relación interna de la rama.');
    }
}
