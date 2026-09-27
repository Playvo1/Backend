<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_reset_otp_succeeds_with_a_valid_code(): void
    {
        $user = User::factory()->create();

        $code = VerificationCode::create([
            'user_id' => $user->id,
            'code' => '654321',
            'type' => 'password_reset',
            'expires_at' => now()->addMinutes(10),
            'used_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/verify-reset-otp', [
            'email' => $user->email,
            'otp_code' => '654321',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'OTP verified successfully. You can now reset your password.',
            ]);

        $this->assertNotNull($code->fresh()->verified_at);
    }

    public function test_verify_reset_otp_rejects_an_incorrect_code(): void
    {
        $user = User::factory()->create();

        VerificationCode::create([
            'user_id' => $user->id,
            'code' => '654321',
            'type' => 'password_reset',
            'expires_at' => now()->addMinutes(10),
            'used_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/verify-reset-otp', [
            'email' => $user->email,
            'otp_code' => '000000',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid or expired OTP.',
            ]);
    }

    public function test_verify_reset_otp_fails_validation_for_unknown_email(): void
    {
        $response = $this->postJson('/api/v1/auth/verify-reset-otp', [
            'email' => 'nobody@example.com',
            'otp_code' => '654321',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonValidationErrors(['email']);
    }

    private function createResetCode(User $user, array $overrides = []): VerificationCode
    {
        return VerificationCode::create(array_merge([
            'user_id' => $user->id,
            'code' => '654321',
            'type' => 'password_reset',
            'expires_at' => now()->addMinutes(10),
            'used_at' => null,
        ], $overrides));
    }

    private function resetPassword(User $user, array $overrides = [])
    {
        return $this->postJson('/api/v1/auth/reset-password', array_merge([
            'email' => $user->email,
            'code' => '654321',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ], $overrides));
    }

    public function test_reset_password_succeeds_with_a_valid_code(): void
    {
        $user = User::factory()->create();
        $code = $this->createResetCode($user);

        $this->resetPassword($user)
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Password updated',
            ]);

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        $this->assertNotNull($code->fresh()->used_at);
    }

    public function test_reset_password_succeeds_after_verify_reset_otp_with_the_same_code(): void
    {
        $user = User::factory()->create();
        $this->createResetCode($user);

        $this->postJson('/api/v1/auth/verify-reset-otp', [
            'email' => $user->email,
            'otp_code' => '654321',
        ])->assertStatus(200);

        $this->resetPassword($user)->assertStatus(200);

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_reset_password_cannot_be_hijacked_after_the_owner_verifies_the_code(): void
    {
        $user = User::factory()->create();
        $this->createResetCode($user);

        // The real owner verifies their code...
        $this->postJson('/api/v1/auth/verify-reset-otp', [
            'email' => $user->email,
            'otp_code' => '654321',
        ])->assertStatus(200);

        // ...and someone who only knows the email tries to set the password without it.
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'password' => 'hacked-password',
            'password_confirmation' => 'hacked-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->assertFalse(Hash::check('hacked-password', $user->fresh()->password));
    }

    public function test_reset_password_rejects_an_incorrect_code(): void
    {
        $user = User::factory()->create();
        $this->createResetCode($user);

        $this->resetPassword($user, ['code' => '000000'])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid or expired code.',
            ]);

        $this->assertFalse(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_reset_password_rejects_an_expired_code(): void
    {
        $user = User::factory()->create();
        $this->createResetCode($user, ['expires_at' => now()->subMinute()]);

        $this->resetPassword($user)
            ->assertStatus(422)
            ->assertJson(['message' => 'Invalid or expired code.']);
    }

    public function test_reset_password_code_cannot_be_used_twice(): void
    {
        $user = User::factory()->create();
        $this->createResetCode($user);

        $this->resetPassword($user)->assertStatus(200);

        $this->resetPassword($user, [
            'password' => 'second-password-456',
            'password_confirmation' => 'second-password-456',
        ])->assertStatus(422)
            ->assertJson(['message' => 'Invalid or expired code.']);

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_reset_password_code_belongs_to_one_account_only(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->createResetCode($owner);

        $this->resetPassword($other)
            ->assertStatus(422)
            ->assertJson(['message' => 'Invalid or expired code.']);
    }

    public function test_reset_password_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->resetPassword($user, ['code' => '000000'])->assertStatus(422);
        }

        $this->resetPassword($user, ['code' => '000000'])
            ->assertStatus(429)
            ->assertJson(['success' => false, 'data' => null]);
    }

    public function test_reset_password_fails_validation_when_confirmation_does_not_match(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonValidationErrors(['password']);
    }
}
