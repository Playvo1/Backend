<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_a_code_for_an_existing_email(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'exists@example.com']);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'exists@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => null,
                'message' => 'If this email exists, a reset code was sent',
                'errors' => null,
            ]);

        $this->assertDatabaseHas('verification_codes', [
            'user_id' => $user->id,
            'type' => 'password_reset',
        ]);

        Mail::assertSent(OtpMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_forgot_password_returns_the_same_response_for_a_non_existing_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'does-not-exist@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => null,
                'message' => 'If this email exists, a reset code was sent',
                'errors' => null,
            ]);

        $this->assertDatabaseCount('verification_codes', 0);
        Mail::assertNothingSent();
    }

    /**
     * Regression test for the email-enumeration fix: an existing and a
     * non-existing email must produce byte-for-byte identical responses
     * (status + body), so a client can never use this endpoint to probe
     * which emails are registered.
     */
    public function test_forgot_password_response_is_identical_for_existing_and_non_existing_email(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'known@example.com']);

        $existingResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'known@example.com',
        ]);

        $unknownResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'unknown@example.com',
        ]);

        $this->assertSame($existingResponse->status(), $unknownResponse->status());
        $this->assertSame($existingResponse->json(), $unknownResponse->json());
    }
}
