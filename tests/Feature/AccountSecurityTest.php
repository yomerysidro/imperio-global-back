<?php

namespace Tests\Feature;

use App\Mail\CreateUserMail;
use App\Mail\PasswordUserMail;
use App\Models\User;
use App\Models\VerificationCodeUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        Mail::fake();
        $user = $this->createUser('member@example.test');
        $oldPassword = $user->password;

        $recovery = $this->postJson('/api/v1/auth/recover-password', [
            'email' => ' MEMBER@example.test ',
        ])->assertOk()->assertJsonPath('success', true);

        $verification = VerificationCodeUser::findOrFail($recovery->json('data.validation'));
        Mail::assertSent(CreateUserMail::class, fn ($mail) => $mail->hasTo($user->email));

        $this->postJson('/api/v1/auth/validate-code/'.$verification->id, [
            'code' => $verification->code,
        ])->assertOk()->assertJsonPath('success', true);

        $user->refresh();
        $this->assertNotSame($oldPassword, $user->password);
        $this->assertTrue($verification->fresh()->state);
        Mail::assertSent(PasswordUserMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_an_expired_recovery_code_cannot_change_the_password(): void
    {
        Mail::fake();
        $user = $this->createUser('expired@example.test');
        $oldPassword = $user->password;
        $verification = VerificationCodeUser::create([
            'user_id' => $user->id,
            'type' => 2,
            'code' => '1234',
        ]);
        $verification->forceFill(['created_at' => now()->subMinutes(16)])->saveQuietly();

        $this->postJson('/api/v1/auth/validate-code/'.$verification->id, [
            'code' => '1234',
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame($oldPassword, $user->fresh()->password);
        Mail::assertNotSent(PasswordUserMail::class);
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
