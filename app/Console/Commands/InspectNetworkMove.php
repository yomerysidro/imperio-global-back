<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Core\NetworkTreeService;
use Illuminate\Console\Command;

class InspectNetworkMove extends Command
{
    protected $signature = 'mlm:inspect-move {source} {destination} {--summary}';
    protected $description = 'Read-only inspection of a proposed branch move';

    public function handle(NetworkTreeService $tree): int
    {
        $snapshot = $tree->snapshotFromRoot();
        $nodes = $snapshot['nodes'];
        $source = strtoupper($this->argument('source'));
        $destination = strtoupper($this->argument('destination'));
        $branch = [];
        $walk = function (string $key) use (&$walk, &$branch, $nodes): void {
            if (!isset($nodes[$key]) || isset($branch[$key])) return;
            $branch[$key] = true;
            foreach ($nodes[$key]['children'] as $child) $walk(strtoupper($child));
        };
        $walk($source);
        $route = function (string $key) use ($nodes): array {
            $out = []; $seen = [];
            while (isset($nodes[$key]) && !isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = ['uuid' => $nodes[$key]['user_code'], 'name' => $nodes[$key]['user_name'],
                    'level' => $nodes[$key]['level'], 'sponsor_uuid' => $nodes[$key]['parent_code']];
                if (!$nodes[$key]['parent_code']) break;
                $key = strtoupper($nodes[$key]['parent_code']);
            }
            return $out;
        };
        $descendants = array_values(array_filter(array_keys($branch), fn ($key) => $key !== $source));
        $result = [
            'database' => app('db')->connection()->getDatabaseName(),
            'source' => $nodes[$source] ?? null,
            'destination' => $nodes[$destination] ?? null,
            'source_sponsor' => isset($nodes[$source]) && $nodes[$source]['parent_code']
                ? ($nodes[strtoupper($nodes[$source]['parent_code'])] ?? null) : null,
            'descendant_count' => count($descendants),
            'affected_count' => count($branch),
            'destination_in_source_branch' => isset($branch[$destination]),
            'source_route' => isset($nodes[$source]) ? $route($source) : [],
            'destination_route' => isset($nodes[$destination]) ? $route($destination) : [],
            'cycles' => $snapshot['integrity']['cycles'] ?? [],
            'orphan_relations' => $snapshot['integrity']['orphan_relations'] ?? [],
            'multiple_parents' => $snapshot['integrity']['multiple_parents'] ?? [],
            'unconnected_user_count' => count($snapshot['integrity']['unconnected_users'] ?? []),
            'audit_count' => app('db')->table('network_move_audits')->count(),
        ];
        $branchUsers = User::whereIn('uuid', array_map(fn ($key) => $nodes[$key]['user_code'], array_keys($branch)))
            ->get(['uuid', 'name'])->keyBy(fn ($user) => strtoupper($user->uuid));
        $result['direct_child_count'] = isset($nodes[$source]) ? count($nodes[$source]['children']) : 0;
        $activation = app(\App\Services\Core\ActivationService::class);
        $directProfiles = User::whereIn('uuid', array_map(fn ($child) => $nodes[strtoupper($child)]['user_code'] ?? $child,
            $nodes[$source]['children'] ?? []))->get();
        $allProfiles = User::whereIn('uuid', array_map(fn ($key) => $nodes[$key]['user_code'], $descendants))->get();
        $result['direct_active_count'] = $directProfiles->filter(fn (User $member) => $activation->isActive($member))->count();
        $result['network_active_count'] = $allProfiles->filter(fn (User $member) => $activation->isActive($member))->count();
        $result['descendants'] = $this->option('summary') ? null : array_map(fn ($key) => [
            'uuid' => $nodes[$key]['user_code'], 'name' => $branchUsers[$key]->name ?? null,
            'sponsor_uuid' => $nodes[$key]['parent_code'], 'level' => $nodes[$key]['level'],
        ], $descendants);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
