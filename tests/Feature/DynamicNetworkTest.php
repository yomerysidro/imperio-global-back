<?php

namespace Tests\Feature;

use App\Models\SponsorRelation;
use App\Models\User;
use App\Services\Core\NetworkBranchMoveService;
use App\Services\Core\NetworkTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DynamicNetworkTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql' || !preg_match('/^imperio_mlm_test_[a-z0-9_]+$/i', DB::getDatabaseName())
            || strcasecmp(DB::getDatabaseName(), 'imperiog') === 0) {
            throw new \RuntimeException('Use php tests/run-isolated-mysql.php; the configured database is protected.');
        }
    }

    private function account(string $name): User
    {
        return User::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => $name,
            'email' => $name.'@example.test', 'password' => 'test-password-long', 'is_admin' => $name === 'company']);
    }

    public function test_dynamic_line_and_branch_move_preserve_internal_relations_and_audit(): void
    {
        $root = $this->account('company'); $a = $this->account('a');
        $b = $this->account('b'); $child = $this->account('child');
        config(['mlm.root_uuid' => $root->uuid]);
        foreach ([[$a, $root], [$b, $root], [$child, $a]] as [$user, $sponsor]) {
            SponsorRelation::create(['user_code' => $user->uuid, 'sponsor_code' => $sponsor->uuid, 'state' => true]);
        }
        Passport::actingAs($root);
        $this->assertSame($root->uuid, app(NetworkTreeService::class)->sponsorCode($a->uuid));
        $result = app(NetworkBranchMoveService::class)->move($a->uuid, $b->uuid, $root->id);
        $this->assertSame(2, $result['total_affected']);
        $this->assertDatabaseHas('sponsor_relations', ['user_code' => $child->uuid, 'sponsor_code' => $a->uuid]);
        $this->assertDatabaseHas('network_move_audits', ['user_code' => $a->uuid, 'new_sponsor_code' => $b->uuid]);
        $this->getJson('/api/v1/admin/network/genealogy?perPage=2')->assertOk()->assertJsonPath('total', 4);
        $invalid = app(NetworkBranchMoveService::class)->preview($b->uuid, $child->uuid, $root->id);
        $this->assertFalse($invalid['valid']);
    }

    public function test_registration_adds_only_a_real_relation(): void
    {
        $root = $this->account('company'); config(['mlm.root_uuid' => $root->uuid]);
        $uuid = 'NEW-'.bin2hex(random_bytes(6));
        $this->postJson('/api/v1/auth/register', ['name' => 'New member', 'email' => 'new@example.test',
            'dni' => $uuid, 'password' => 'password-long', 'sponsor_code' => $root->uuid])->assertOk();
        $this->assertDatabaseHas('sponsor_relations', ['user_code' => $uuid, 'sponsor_code' => $root->uuid]);
    }

    public function test_outer_rollback_reverts_the_move_and_its_audit(): void
    {
        $root = $this->account('company'); $a = $this->account('a'); $b = $this->account('b');
        config(['mlm.root_uuid' => $root->uuid]);
        foreach ([$a, $b] as $user) SponsorRelation::create([
            'user_code' => $user->uuid, 'sponsor_code' => $root->uuid, 'state' => true,
        ]);
        try {
            DB::transaction(function () use ($root, $a, $b) {
                app(NetworkBranchMoveService::class)->move($a->uuid, $b->uuid, $root->id);
                throw new \RuntimeException('injected failure');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('injected failure', $e->getMessage());
        }
        $this->assertDatabaseHas('sponsor_relations', ['user_code' => $a->uuid, 'sponsor_code' => $root->uuid]);
        $this->assertDatabaseCount('network_move_audits', 0);
    }

    public function test_mysql_root_lock_excludes_a_second_connection(): void
    {
        $root = $this->account('company'); config(['mlm.root_uuid' => $root->uuid]);
        app(NetworkTreeService::class)->lockRootForMutation();
        $c = config('database.connections.mysql');
        $other = new \PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'],
            $c['username'], $c['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();
        try {
            $statement = $other->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $statement->execute([$root->id]);
            $this->fail('El segundo escritor no debe obtener el bloqueo.');
        } catch (\PDOException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1]);
        } finally {
            $other->rollBack();
        }
    }
}
