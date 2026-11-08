<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Passport\Passport;
use Tests\TestCase;

class NetworkMoveStatusEndpointTest extends TestCase
{
    public function test_authenticated_admin_gets_same_local_availability_as_confirmation_gate(): void
    {
        config([
            'network_moves.backend_certified' => false,
            'network_moves.confirmation_enabled' => true,
            'network_moves.local_testing_enabled' => true,
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.database' => 'imperiog',
            'app.frontend_origins' => 'http://localhost:4200',
        ]);
        Passport::actingAs(new User(['is_admin' => true]));

        $response = $this->withHeader('Origin', 'http://localhost:4200')
            ->getJson('/api/v1/admin/network/moves/status');

        $response->assertOk()->assertExactJson(['confirmation_enabled' => true, 'mode' => 'local_test']);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_status_remains_protected_and_cors_preflight_is_supported(): void
    {
        $this->getJson('/api/v1/admin/network/moves/status')->assertUnauthorized();
        $this->withHeaders([
            'Origin' => 'http://localhost:4200',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'authorization',
        ])->call('OPTIONS', '/api/v1/admin/network/moves/status')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
    }
}
