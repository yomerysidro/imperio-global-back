<?php

namespace Tests\Feature;

use App\Mail\CreateUserMail;
use App\Mail\PasswordUserMail;
use App\Models\User;
use App\Models\VerificationCodeUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_password_reset_without_a_recovery_code_does_not_exist(): void
    {
        $this->postJson('/api/v1/reset-password', [
            'email' => 'victim@example.test',
            'password' => 'attacker-password',
        ])->assertNotFound();
    }

    public function test_any_registered_user_can_recover_their_password_with_the_emailed_code(): void
    {
        // Exercise per-recovery limits independently of the route's IP throttle.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Mail::fake();
        $user = $this->createUser('member@example.test');
        $oldPassword = $user->password;

        $recovery = $this->postJson('/api/v1/auth/recover-password', [
            'email' => ' MEMBER@example.test ',
        ])->assertOk()->assertJsonPath('success', true);

        $verification = VerificationCodeUser::findOrFail($recovery->json('data.validation'));
        Mail::assertSent(CreateUserMail::class, fn ($mail) => $mail->hasTo($user->email));

        $code = Mail::sent(CreateUserMail::class)->first()->mailData['code'];
        $this->assertSame('----', $verification->code);
        $this->assertArrayNotHasKey('code', $recovery->json('data'));
        $this->postJson('/api/v1/auth/validate-code/'.$verification->id, [
            'code' => '0000',
        ])->assertStatus(422);

        $authorization = $this->postJson('/api/v1/auth/validate-code/'.$verification->id, [
            'code' => $code,
        ])->assertOk()->assertJsonPath('success', true);
        $this->assertSame($oldPassword, $user->fresh()->password);
        $this->postJson('/api/v1/auth/validate-code/'.$verification->id, ['code' => $code])->assertStatus(422);

        $payload = [
            'validation' => $verification->id,
            'recovery_token' => $authorization->json('data.recovery_token'),
            'password' => 'Chosen-password-2026!',
            'password_confirmation' => 'different-password',
        ];
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertStatus(422);
        $payload['password_confirmation'] = $payload['password'];
        $this->postJson('/api/v1/auth/reset-password', array_replace($payload, [
            'recovery_token' => str_repeat('0', 64),
        ]))->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertFalse(Hash::check('original-password', $user->fresh()->password));
        $this->assertTrue((bool) $verification->fresh()->state);
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertStatus(422);
        $this->assertTrue(auth()->guard('web')->validate(['email' => $user->email, 'password' => $payload['password']]));
        $this->assertFalse(auth()->guard('web')->validate(['email' => $user->email, 'password' => 'original-password']));
        Mail::assertNotSent(PasswordUserMail::class);

        // A second isolated recovery is exhausted after five incorrect guesses.
        $this->travel(61)->seconds();
        $second = $this->postJson('/api/v1/auth/recover-password', ['email' => $user->email])->assertOk();
        $secondId = $second->json('data.validation');
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/validate-code/'.$secondId, ['code' => '0000'])->assertStatus(422);
        }
        $this->assertTrue((bool) VerificationCodeUser::findOrFail($secondId)->state);
        $this->assertNull(Cache::get('password-recovery:'.$secondId));
        $this->travelBack();
    }
    public function test_an_expired_recovery_code_cannot_change_the_password(): void
    {
        Mail::fake();
        $user = $this->createUser('expired@example.test');
        $oldPassword = $user->password;
        $recovery = $this->postJson('/api/v1/auth/recover-password', ['email' => $user->email])->assertOk();
        $verification = VerificationCodeUser::findOrFail($recovery->json('data.validation'));
        $code = Mail::sent(CreateUserMail::class)->first()->mailData['code'];
        $this->travel(16)->minutes();

        $this->postJson('/api/v1/auth/validate-code/'.$verification->id, [
            'code' => $code,
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame($oldPassword, $user->fresh()->password);
        Mail::assertNotSent(PasswordUserMail::class);
        $this->travelBack();
    }

    public function test_non_admin_cannot_change_an_email_but_admin_can(): void
    {
        $member = $this->createUser('member@example.test');
        $target = $this->createUser('target@example.test');

        Passport::actingAs($member);
        $this->postJson('/api/v1/users/modify', [
            'userCode' => $target->uuid,
            'userFullName' => $target->name,
            'userEmail' => 'stolen@example.test',
        ])->assertForbidden();
        $this->assertSame('target@example.test', $target->fresh()->email);

        $admin = $this->createUser('admin@example.test', true);
        Passport::actingAs($admin);
        $this->postJson('/api/v1/users/modify', [
            'userCode' => $target->uuid,
            'userFullName' => $target->name,
            'userEmail' => ' NEW-TARGET@example.test ',
        ])->assertOk()->assertJsonPath('success', true);
        $this->assertSame('new-target@example.test', $target->fresh()->email);
    }

    private function createUser(string $email, bool $isAdmin = false): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => $email,
            'uuid' => strtoupper(strtok($email, '@')).'-'.uniqid(),
            'password' => Hash::make('original-password'),
            'is_admin' => $isAdmin,
        ]);
    }
}
