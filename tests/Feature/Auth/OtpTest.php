<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpMail;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_otp_creates_a_code_and_mails_it_for_an_unverified_user(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create();

        $response = $this->postJson('/api/v1/auth/send-otp', [
            'email' => $user->email,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message', 'errors'])
            ->assertJson([
                'success' => true,
                'message' => 'OTP sent successfully to your email.',
            ]);

        $this->assertDatabaseHas('verification_codes', [
            'user_id' => $user->id,
            'type' => 'registration',
        ]);

        Mail::assertSent(OtpMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_send_otp_fails_validation_for_unknown_email(): void
    {
        $response = $this->postJson('/api/v1/auth/send-otp', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonValidationErrors(['email']);
    }

    public function test_send_otp_rejects_an_already_verified_email(): void
    {
        $user = User::factory()->create(); // factory default already sets email_verified_at

        $response = $this->postJson('/api/v1/auth/send-otp', [
            'email' => $user->email,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Email is already verified.',
            ]);
    }

    public function test_verify_otp_succeeds_with_a_valid_code(): void
    {
        $user = User::factory()->unverified()->create();

        $code = VerificationCode::create([
            'user_id' => $user->id,
            'code' => '123456',
            'type' => 'registration',
            'expires_at' => now()->addMinutes(10),
            'used_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'email' => $user->email,
            'otp_code' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Email verified',
            ]);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull($code->fresh()->used_at);
    }

    public function test_verify_otp_rejects_an_incorrect_code(): void
    {
        $user = User::factory()->unverified()->create();

        VerificationCode::create([
            'user_id' => $user->id,
            'code' => '123456',
            'type' => 'registration',
            'expires_at' => now()->addMinutes(10),
            'used_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'email' => $user->email,
            'otp_code' => '999999',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid or expired OTP.',
            ]);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verify_otp_rejects_an_expired_code(): void
    {
        $user = User::factory()->unverified()->create();

        VerificationCode::create([
            'user_id' => $user->id,
            'code' => '123456',
            'type' => 'registration',
            'expires_at' => now()->subMinute(),
            'used_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'email' => $user->email,
            'otp_code' => '123456',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid or expired OTP.',
            ]);
    }

    public function test_verify_otp_fails_validation_for_malformed_code(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'email' => $user->email,
            'otp_code' => '123',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonValidationErrors(['otp_code']);
    }
}
