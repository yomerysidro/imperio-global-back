<?php

namespace Tests\Feature;

use Tests\TestCase;

class RegistrationSponsorValidationTest extends TestCase
{
    public function test_sponsor_verification_is_public_and_requires_a_code(): void
    {
        $response = $this->getJson('/api/v1/auth/sponsor-verify');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('code', 'data');
    }

    public function test_public_registration_requires_a_sponsor_code(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Usuario de prueba',
            'email' => 'registro-sin-patrocinador@example.test',
            'dni' => 'TEST-NO-SPONSOR',
            'password' => 'password-seguro',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('sponsor_code', 'data');
    }
}
