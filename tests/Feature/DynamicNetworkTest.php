<?php

namespace Tests\Feature;

use App\Models\SponsorRelation;
use App\Models\User;
use App\Services\Core\NetworkBranchMoveService;
use App\Services\Core\NetworkTreeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DynamicNetworkTest extends TestCase
{
    use DatabaseTransactions;

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

    public function test_registration_under_moved_member_is_immediate_and_repeat_does_not_duplicate(): void
    {
        $root = $this->account('company'); $a = $this->account('a'); $b = $this->account('b');
        config(['mlm.root_uuid' => $root->uuid]);
        $affiliation = app(\App\Services\Core\AffiliationService::class);
        $affiliation->attach($a, $root->uuid, 'registration');
        $affiliation->attach($b, $root->uuid, 'registration');
        app(NetworkBranchMoveService::class)->move($a->uuid, $b->uuid, $root->id);
        foreach (range(1, 3) as $index) {
            $uuid = 'TEST-'.\Illuminate\Support\Str::uuid();
            $payload = ['name' => 'New member', 'email' => "new{$index}@example.test",
                'dni' => $uuid, 'password' => 'password-long', 'sponsor_code' => $a->uuid];
            $this->postJson('/api/v1/auth/register', $payload)->assertOk()->assertJsonPath('success', true);
            $user = User::where('uuid', $uuid)->firstOrFail();
            $affiliation->attach($user, $a->uuid, 'registration');
            $this->assertSame(1, SponsorRelation::where('user_code', $uuid)->count());
            $tree = app(NetworkTreeService::class);
            $this->assertTrue($tree->belongsToNetwork($root->uuid, $uuid));
            $this->assertContains($uuid, $tree->directUserCodes($a->uuid));
            Passport::actingAs($user);
            $this->getJson('/api/v1/users/invited-user')->assertOk()->assertJsonPath('data', $a->uuid);
            $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('web')->validate([
                'email' => $payload['email'], 'password' => $payload['password'],
            ]));
            $this->postJson('/api/v1/auth/register', $payload)->assertJsonPath('success', false);
            $this->assertSame(1, SponsorRelation::where('user_code', $uuid)->count());
        }
    }

    public function test_relation_failure_rolls_back_registration(): void
    {
        $root = $this->account('company'); config(['mlm.root_uuid' => $root->uuid]);
        SponsorRelation::creating(function () { throw new \RuntimeException('injected relation failure'); });
        try {
            $this->postJson('/api/v1/auth/register', ['name' => 'Rollback', 'email' => 'rollback@example.test',
                'dni' => 'ROLLBACK-FIXTURE', 'password' => 'password-long', 'sponsor_code' => $root->uuid])
                ->assertJsonPath('success', false);
            $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
        } finally { SponsorRelation::flushEventListeners(); }
    }

    public function test_invalid_and_disconnected_sponsors_cannot_register(): void
    {
        $root = $this->account('company'); $orphan = $this->account('orphan');
        config(['mlm.root_uuid' => $root->uuid]);
        foreach (['NONEXISTENT-FIXTURE', $orphan->uuid] as $code) {
            $this->postJson('/api/v1/auth/register', ['name' => 'Invalid', 'email' => 'invalid@example.test',
                'dni' => 'INVALID-FIXTURE', 'password' => 'password-long', 'sponsor_code' => $code])->assertStatus(422);
        }
        $this->assertDatabaseMissing('users', ['email' => 'invalid@example.test']);
    }

    public function test_large_networks_and_immutable_existing_parent(): void
    {
        $root = $this->account('company'); config(['mlm.root_uuid' => $root->uuid]);
        $affiliation = app(\App\Services\Core\AffiliationService::class);
        $parent = $root;
        foreach (range(1, 320) as $index) {
            $user = $this->account('member'.$index);
            $affiliation->attach($user, $parent->uuid, 'registration');
            if (in_array($index, [50, 160, 320], true)) {
                $snapshot = app(NetworkTreeService::class)->snapshotFromRoot();
                $this->assertCount($index + 1, $snapshot['nodes']);
                $this->assertEmpty($snapshot['integrity']['cycles']);
                $this->assertEmpty($snapshot['integrity']['multiple_parents']);
                $this->assertTrue(app(NetworkTreeService::class)->belongsToNetwork($root->uuid, $user->uuid));
            }
            if ($index === 1) $parent = $user;
        }
        $this->expectException(\RuntimeException::class);
        $affiliation->attach($user, $root->uuid, 'invitation');
    }

    public function test_administrative_registration_persists_relation_before_commissions(): void
    {
        $root = $this->account('company'); config(['mlm.root_uuid' => $root->uuid]);
        $pack = \App\Models\Pack::create(['title' => 'Fixture pack', 'price' => 100, 'points' => 40,
            'category' => 'Producto', 'state' => true, 'discount' => 0]);
        Passport::actingAs($root);
        $response = $this->postJson('/api/v1/users/create-user', ['name' => 'Admin member',
            'email' => 'admin-member@example.test', 'dni' => 'ADMIN-FIXTURE',
            'sponsor' => $root->uuid, 'plan' => $pack->id, 'password' => 'password-long']);
        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('sponsor_relations', ['user_code' => 'ADMIN-FIXTURE',
            'sponsor_code' => $root->uuid, 'state' => true]);
        $this->assertDatabaseHas('payment_order_points', ['user_code' => 'ADMIN-FIXTURE',
            'sponsor_code' => $root->uuid, 'type' => 'B', 'point' => 40]);
    }

    public function test_new_commission_uses_current_eligible_chain_and_retry_preserves_distribution(): void
    {
        $root = $this->account('company'); $inactive = $this->account('inactive');
        $member = $this->account('buyer'); config(['mlm.root_uuid' => $root->uuid]);
        $affiliation = app(\App\Services\Core\AffiliationService::class);
        $affiliation->attach($inactive, $root->uuid, 'registration');
        $affiliation->attach($member, $inactive->uuid, 'registration');
        $pack = \App\Models\Pack::create(['title' => 'Commission fixture', 'price' => 100,
            'points' => 40, 'category' => 'Producto', 'state' => true, 'discount' => 0]);
        \App\Models\SponsorshipPoint::create(['pack_id' => $pack->id,
            'level1' => 10, 'level2' => 5, 'level3' => 0, 'level4' => 0, 'level5' => 0]);
        $order = \App\Models\PaymentOrder::create(['currency' => 'PEN', 'amount' => 100,
            'pack_id' => $pack->id, 'sponsor_code' => $root->uuid, 'token' => 'fixture-'.\Illuminate\Support\Str::uuid()]);
        $commissions = app(\App\Services\Core\CommissionService::class);
        $commissions->confirmPoint($order, $member, $pack);
        $this->assertDatabaseMissing('payment_order_points', ['payment_order_id' => $order->id,
            'user_code' => $inactive->uuid, 'type' => 'P']);
        $this->assertDatabaseHas('payment_order_points', ['payment_order_id' => $order->id,
            'user_code' => $root->uuid, 'type' => 'P', 'level' => 2, 'point' => 2]);
        $before = DB::table('payment_order_points')->where('payment_order_id', $order->id)->orderBy('id')->get();
        app(NetworkBranchMoveService::class)->move($member->uuid, $root->uuid, $root->id);
        $commissions->confirmPoint($order, $member, $pack);
        $this->assertEquals($before, DB::table('payment_order_points')->where('payment_order_id', $order->id)->orderBy('id')->get());
    }

    public function test_invitation_cannot_replace_parent_and_cancellation_keeps_genealogy(): void
    {
        $root = $this->account('company'); $a = $this->account('a'); $member = $this->account('member');
        config(['mlm.root_uuid' => $root->uuid]);
        $affiliation = app(\App\Services\Core\AffiliationService::class);
        $affiliation->attach($a, $root->uuid, 'invitation');
        $affiliation->attach($member, $root->uuid, 'invitation');
        $invite = \App\Models\InviteUser::create(['sponsor_user_id' => $a->id,
            'sponsor_user_code' => $a->uuid, 'token' => (string) \Illuminate\Support\Str::uuid(),
            'state' => true, 'type' => 1, 'expired_time' => now()->addHour()]);
        Passport::actingAs($member);
        $level = DB::transactionLevel();
        $this->postJson('/api/v1/users/invited-confirm', ['token' => $invite->token, 'accept' => true])
            ->assertJsonPath('success', false);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertDatabaseMissing('guests_token_users', ['guest_user_code' => $member->uuid]);
        $this->assertDatabaseHas('invite_users', ['id' => $invite->id, 'state' => true]);
        $this->postJson('/api/v1/users/invited-removed')->assertOk();
        $this->assertSame($root->uuid, app(NetworkTreeService::class)->sponsorCode($member->uuid));
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
            $statement = $other->prepare('SELECT id FROM ig_users WHERE id = ? FOR UPDATE');
            $statement->execute([$root->id]);
            $this->fail('El segundo escritor no debe obtener el bloqueo.');
        } catch (\PDOException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1]);
        } finally {
            $other->rollBack();
        }
    }
}
