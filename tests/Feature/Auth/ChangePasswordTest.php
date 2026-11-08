<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/auth/password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(string $role = 'player', array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['status' => 'active'], $attributes));
        $user->assignRole($role);

        return $user;
    }

    private function token(User $user, string $name = 'auth_token'): string
    {
        return $user->createToken($name)->plainTextToken;
    }

    /**
     * Sends a request with a bearer token, resolving the user afresh each time.
     */
    private function asToken(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->json($method, $uri, $data);
    }

    private function change(string $token, array $data): TestResponse
    {
        return $this->asToken($token, 'PUT', self::URL, $data);
    }

    private function login(User $user, string $password): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ]);
    }

    public static function roles(): array
    {
        return [['player'], ['venue_owner'], ['admin']];
    }

    #[DataProvider('roles')]
    public function test_each_role_can_change_password(string $role): void
    {
        $user = $this->user($role);

        $this->change($this->token($user), [
            'current_password' => 'password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => null,
                'message' => 'Password changed successfully.',
                'errors' => null,
            ]);

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('new-secret-123', $fresh->password));
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);

        $this->login($user, 'password')->assertStatus(401);
        $this->login($user, 'new-secret-123')->assertOk();
    }

    public function test_other_tokens_are_revoked_and_current_one_kept(): void
    {
        $user = $this->user('venue_owner');
        $other = $this->token($user, 'other_device');
        $current = $this->token($user);

        $this->change($current, [
            'current_password' => 'password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertOk();

        $this->assertSame(1, $user->tokens()->count());

        $this->asToken($current, 'GET', '/api/v1/notifications')->assertOk();
        $this->asToken($other, 'GET', '/api/v1/notifications')->assertStatus(401);
    }

    public function test_wrong_current_password_is_rejected_and_nothing_changes(): void
    {
        $user = $this->user();
        $this->token($user, 'other_device');

        $this->change($this->token($user), [
            'current_password' => 'not-my-password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.')
            ->assertJsonMissingPath('errors.password');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('password', $fresh->password));
        $this->assertSame(2, $user->tokens()->count());
        $this->assertSame(0, (int) $fresh->failed_login_attempts);
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        $user = $this->user();

        $this->change($this->token($user), [
            'current_password' => 'password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'something-else',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_short_password_is_rejected(): void
    {
        $user = $this->user();

        $this->change($this->token($user), [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_missing_fields_are_rejected(): void
    {
        $this->change($this->token($this->user()), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password', 'password']);
    }

    public function test_new_password_must_differ_from_current(): void
    {
        $user = $this->user();

        $this->change($this->token($user), [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The new password must be different from the current password.');
    }

    public function test_account_without_password_must_use_forgot_password(): void
    {
        $user = $this->user('player', ['password' => null, 'google_id' => 'google-123']);
        $this->assertNull($user->fresh()->password);

        $this->change($this->token($user), [
            'current_password' => 'anything',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'This account has no password. Use "forgot password" to set one.');

        $this->assertNull($user->fresh()->password);
    }

    public function test_unauthenticated_request_gets_401(): void
    {
        $this->putJson(self::URL, [
            'current_password' => 'password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_attempts_are_throttled_per_user(): void
    {
        $user = $this->user();
        $token = $this->token($user);
        $wrong = [
            'current_password' => 'guess',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->change($token, $wrong)->assertStatus(422);
        }

        $this->change($token, $wrong)
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertHeader('Retry-After');

        // Another user has their own limit.
        $other = $this->user();
        $this->change($this->token($other), $wrong)->assertStatus(422);

        // The limit resets after a minute.
        $this->travel(61)->seconds();
        $this->change($token, $wrong)->assertStatus(422);
    }
}
