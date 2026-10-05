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

    public function test_reset_password_succeeds_after_a_verified_otp(): void
    {
        $user = User::factory()->create();

        VerificationCode::create([
            'user_id' => $user->id,
            'code' => '654321',
            'type' => 'password_reset',
            'expires_at' => now()->addMinutes(10),
            'used_at' => null,
            'verified_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Password updated',
            ]);

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_reset_password_fails_without_a_verified_otp(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'OTP verification required or expired.',
            ]);
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
