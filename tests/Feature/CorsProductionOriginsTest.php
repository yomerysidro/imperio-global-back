<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsProductionOriginsTest extends TestCase
{
    public function test_login_preflight_allows_production_origin_with_authorization_headers(): void
    {
        $response = $this->options('/api/v1/login', [], [
            'Origin' => 'https://imperioglobal.org',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,authorization',
        ]);

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'https://imperioglobal.org');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsString('Origin', $response->headers->get('Vary'));
        $this->assertStringContainsString('POST', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('authorization', strtolower($response->headers->get('Access-Control-Allow-Headers')));
    }

    public function test_login_preflight_allows_local_angular_origin(): void
    {
        $response = $this->options('/api/v1/login', [], [
            'Origin' => 'http://localhost:4200',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ]);

        $response->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
    }

    public function test_login_preflight_rejects_unapproved_origins_without_cors_grant(): void
    {
        foreach (['https://evil.example', 'https://www.imperioglobal.org'] as $origin) {
            $response = $this->options('/api/v1/login', [], [
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'POST',
            ]);

            $response->assertNoContent();
            $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'), $origin);
            $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'), $origin);
        }
    }

    public function test_login_response_includes_exact_cors_origin(): void
    {
        $response = $this->withHeader('Origin', 'https://imperioglobal.org')
            ->postJson('/api/v1/login', []);

        $this->assertContains($response->getStatusCode(), [400, 422]);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://imperioglobal.org');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsString('Origin', $response->headers->get('Vary'));
    }
}
