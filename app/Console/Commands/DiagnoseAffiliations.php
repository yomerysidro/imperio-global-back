<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\SponsorRelation;
use App\Services\Core\CorporateRootService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseAffiliations extends Command
{
    protected $signature = 'mlm:diagnose-affiliations {--uuid= : Include exact stored evidence for one UUID}';
    protected $description = 'Read-only affiliation integrity and exact evidence report; never repairs data';

    public function handle(): int
    {
        DB::statement('SET TRANSACTION READ ONLY');
        DB::beginTransaction();
        try {
            $root = strtoupper(app(CorporateRootService::class)->uuid());
            $users = User::get(['id', 'uuid'])->keyBy(fn ($u) => strtoupper($u->uuid));
            $relations = SponsorRelation::get()->groupBy(fn ($r) => strtoupper($r->user_code));
            $issues = [];
            foreach ($users as $code => $user) {
                if ((string) $code === $root) continue;
                $rows = $relations->get($code, collect());
                $active = $rows->where('state', true);
                $reasons = [];
                if ($rows->isEmpty()) $reasons[] = 'missing_relation';
                elseif ($active->isEmpty()) $reasons[] = 'inactive_relation';
                if ($rows->count() > 1) $reasons[] = 'multiple_stored_relations';
                $cursor = (string) $code; $path = [];
                while ($cursor !== $root) {
                    if (isset($path[$cursor])) { $reasons[] = 'cycle'; break; }
                    $path[$cursor] = true;
                    if (!$users->has($cursor)) { $reasons[] = 'missing_sponsor'; break; }
                    $parents = $relations->get($cursor, collect())->where('state', true);
                    if ($parents->count() !== 1) { $reasons[] = 'disconnected_or_multiple_parents'; break; }
                    $cursor = strtoupper($parents->first()->sponsor_code);
                }
                if ($reasons) $issues[] = ['uuid' => $user->uuid, 'reasons' => array_values(array_unique($reasons)), 'path' => array_keys($path)];
            }
            $invalid = [];
            $byId = $users->keyBy('id');
            foreach ($relations->flatten(1) as $row) {
                foreach (['user_code', 'sponsor_code'] as $field) {
                    if (!$users->has(strtoupper($row->$field))) {
                        $invalid[] = ['relation' => $row->toArray(), 'field' => $field,
                            'possible_internal_id_uuid' => $byId->get($row->$field)?->uuid];
                    }
                }
            }
            $evidence = null;
            if ($uuid = $this->option('uuid')) {
                $user = User::where('uuid', $uuid)->first(['id', 'uuid']);
                $evidence = ['user' => $user?->getAttributes(), 'relations' => SponsorRelation::where('user_code', $uuid)->get(),
                    'invitations' => DB::table('guests_token_users')->where('guest_user_code', $uuid)->get(),
                    'moves' => DB::getSchemaBuilder()->hasTable('network_move_audits')
                        ? DB::table('network_move_audits')->where('user_code', $uuid)->get() : [],
                    'orders' => $user ? DB::table('payment_logs')->join('payment_orders', 'payment_orders.id', '=', 'payment_logs.payment_order_id')
                        ->where('payment_logs.user_id', $user->id)->get(['payment_orders.id', 'payment_orders.sponsor_code', 'payment_orders.created_at', 'payment_logs.state']) : []];
            }
            $this->line(json_encode(['database' => DB::getDatabaseName(), 'root_uuid' => $root,
                'issues' => $issues, 'invalid_references' => $invalid, 'evidence' => $evidence], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } finally {
            DB::rollBack();
        }
    }
}
