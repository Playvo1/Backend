<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpMail;
use App\Models\User;
use App\Models\VerificationCode;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegisterPlayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_player_can_register_with_valid_data(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/register/player', [
            'name' => 'Jane Player',
            'email' => 'jane.player@example.com',
            'phone' => '01000000001',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['user_id', 'email', 'otp_sent'], 'message', 'errors'])
            ->assertJson([
                'success' => true,
                'message' => 'Account created, a verification code was sent to your email',
                'errors' => null,
            ])
            ->assertJsonPath('data.email', 'jane.player@example.com')
            ->assertJsonPath('data.otp_sent', true);

        $user = User::where('email', 'jane.player@example.com')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('player'));
        $this->assertNull($user->email_verified_at);
        $this->assertSame('active', $user->status);
    }

    public function test_registration_emails_a_verification_code_that_verifies_the_account(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register/player', [
            'name' => 'Jane Player',
            'email' => 'jane.player@example.com',
            'phone' => '01000000001',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        $user = User::where('email', 'jane.player@example.com')->firstOrFail();
        $code = VerificationCode::where('user_id', $user->id)->where('type', 'registration')->firstOrFail();

        Mail::assertSent(OtpMail::class, fn (OtpMail $mail) => $mail->hasTo('jane.player@example.com'));
        $this->assertTrue(Carbon::parse($code->expires_at)->isFuture());

        $this->postJson('/api/v1/auth/verify-otp', [
            'email' => 'jane.player@example.com',
            'otp_code' => (string) $code->code,
        ])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_account_is_created_even_when_the_code_email_fails(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->postJson('/api/v1/auth/register/player', [
            'name' => 'Jane Player',
            'email' => 'jane.player@example.com',
            'phone' => '01000000001',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.otp_sent', false)
            ->assertJsonPath('message', 'Account created, call send-otp to receive a verification code');

        $this->assertTrue(User::where('email', 'jane.player@example.com')->exists());
    }

    public function test_registration_fails_validation_for_missing_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/register/player', []);

        $response->assertStatus(422)
            ->assertJsonStructure(['success', 'data', 'message', 'errors' => ['name', 'email', 'phone', 'password']])
            ->assertJson([
                'success' => false,
                'data' => null,
            ]);
    }

    public function test_registration_fails_for_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/v1/auth/register/player', [
            'name' => 'Another Player',
            'email' => 'taken@example.com',
            'phone' => '01000000002',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors(['email']);
    }
}
