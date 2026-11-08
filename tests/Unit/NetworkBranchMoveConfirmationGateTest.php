<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\NetworkBranchMoveController;
use App\Services\Core\NetworkBranchMoveService;
use App\Services\Core\NetworkTreeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class NetworkBranchMoveConfirmationGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'network_moves.backend_certified' => false,
            'network_moves.confirmation_enabled' => false,
            'network_moves.local_testing_enabled' => false,
        ]);
        Auth::shouldReceive('user')->andReturn((object) ['is_admin' => true]);
    }

    public function test_single_confirmation_is_locked_before_calling_the_move_service(): void
    {
        $moves = Mockery::mock(NetworkBranchMoveService::class);
        $moves->shouldNotReceive('confirm');

        $response = (new NetworkBranchMoveController($moves))->confirm(
            Request::create('/api/v1/admin/network/moves/confirm', 'POST', ['previewToken' => 'sample'])
        );

        $this->assertSame(423, $response->getStatusCode());
    }

    public function test_batch_confirmation_is_locked_before_calling_the_move_service(): void
    {
        $moves = Mockery::mock(NetworkBranchMoveService::class);
        $moves->shouldNotReceive('confirmBatch');

        $response = (new NetworkBranchMoveController($moves))->confirmBatch(
            Request::create('/api/v1/admin/network/moves/batch/confirm', 'POST', ['previewToken' => 'sample'])
        );

        $this->assertSame(423, $response->getStatusCode());
    }

    public function test_local_testing_opt_in_requires_localhost_and_imperiog(): void
    {
        config([
            'network_moves.backend_certified' => false,
            'network_moves.confirmation_enabled' => true,
            'network_moves.local_testing_enabled' => true,
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.database' => 'imperiog',
        ]);
        $moves = Mockery::mock(NetworkBranchMoveService::class);
        $response = (new NetworkBranchMoveController($moves))->status();
        $this->assertSame(['confirmation_enabled' => true, 'mode' => 'local_test'], $response->getData(true));

        config(['database.connections.mysql.host' => 'db.production.internal']);
        $response = (new NetworkBranchMoveController($moves))->status();
        $this->assertSame(['confirmation_enabled' => false, 'mode' => 'disabled'], $response->getData(true));
    }

    public function test_single_confirmation_accepts_codes_and_executes_one_step_move_when_certified(): void
    {
        config([
            'network_moves.backend_certified' => true,
            'network_moves.confirmation_enabled' => true,
        ]);
        Auth::shouldReceive('id')->andReturn(7);

        $moves = Mockery::mock(NetworkBranchMoveService::class);
        $moves->shouldReceive('move')->once()->with('ROOT1', 'SPONSOR1', 7)
            ->andReturn(['status' => 'confirmed', 'total_affected' => 3]);

        $response = (new NetworkBranchMoveController($moves))->confirm(
            Request::create('/api/v1/admin/network/moves/confirm', 'POST', [
                'userCode' => 'ROOT1',
                'newSponsorCode' => 'SPONSOR1',
            ])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('confirmed', $response->getData(true)['status']);
    }

    public function test_unconnected_users_elsewhere_do_not_invalidate_an_unrelated_branch_path(): void
    {
        $service = new NetworkBranchMoveService(Mockery::mock(NetworkTreeService::class));
        $check = new \ReflectionMethod($service, 'relevantIntegrityErrors');
        $check->setAccessible(true);

        $snapshot = ['integrity' => [
            'unconnected_users' => [['user_code' => 'OUTSIDE1']],
            'cycles' => [['OUTSIDE1', 'OUTSIDE2']],
            'orphan_relations' => [['user_code' => 'OUTSIDE1', 'sponsor_code' => 'OUTSIDE2']],
            'duplicate_user_codes' => [],
            'multiple_parents' => [],
            'missing_users' => [],
            'legacy_parent_mismatches' => [],
        ]];

        $this->assertSame([], $check->invoke($service, $snapshot, ['BRANCH1', 'SPONSOR1', 'DOSB']));

        $snapshot['integrity']['orphan_relations'][] = [
            'user_code' => 'UNKNOWN_CHILD',
            'sponsor_code' => 'BRANCH1',
        ];
        $this->assertNotEmpty($check->invoke($service, $snapshot, ['BRANCH1', 'SPONSOR1', 'DOSB']));
    }
}
