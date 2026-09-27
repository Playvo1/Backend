<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success', 'data' => ['token', 'user' => ['id', 'name', 'email', 'roles']], 'message', 'errors',
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Logged in',
                'errors' => null,
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'email' => 'wrongpass@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'wrongpass@example.com',
            'password' => 'not-the-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonStructure(['success', 'data', 'message', 'errors'])
            ->assertJson([
                'success' => false,
                'data' => null,
                'message' => 'Invalid credentials.',
            ]);

        $this->assertSame(1, $user->fresh()->failed_login_attempts);
    }

    public function test_account_locks_after_five_failed_attempts(): void
    {
        $user = User::factory()->create([
            'email' => 'lockout@example.com',
        ]);

        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'lockout@example.com',
                'password' => 'wrong-password',
            ]);

            $response->assertStatus(401);
        }

        $this->assertSame(4, $user->fresh()->failed_login_attempts);

        $finalAttempt = $this->postJson('/api/v1/auth/login', [
            'email' => 'lockout@example.com',
            'password' => 'wrong-password',
        ]);

        $finalAttempt->assertStatus(423)
            ->assertJson([
                'success' => false,
                'data' => null,
                'message' => 'Account locked, try again in 15 minutes',
            ]);

        $user->refresh();
        $this->assertSame('locked', $user->status);
        $this->assertNotNull($user->locked_until);
        $this->assertSame(5, $user->failed_login_attempts);
    }

    public function test_login_is_rejected_while_account_is_locked_even_with_correct_password(): void
    {
        $user = User::factory()->create([
            'email' => 'stilllocked@example.com',
            'status' => 'locked',
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'stilllocked@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(423)
            ->assertJson([
                'success' => false,
                'data' => null,
                'message' => 'Account locked, try again later',
            ]);

        $this->assertSame('locked', $user->fresh()->status);
    }

    public function test_login_succeeds_and_resets_lockout_once_the_lockout_window_has_passed(): void
    {
        $user = User::factory()->create([
            'email' => 'expiredlock@example.com',
            'status' => 'locked',
            'failed_login_attempts' => 5,
            'locked_until' => now()->subMinute(),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'expiredlock@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Logged in']);

        $user->refresh();
        $this->assertSame('active', $user->status);
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
    }

    public function test_login_rejects_unverified_email(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'unverified-login@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'unverified-login@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Please verify your email before logging in.',
            ]);
    }
}
