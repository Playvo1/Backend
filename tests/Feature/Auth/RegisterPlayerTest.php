<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $response = $this->postJson('/api/v1/auth/register/player', [
            'name' => 'Jane Player',
            'email' => 'jane.player@example.com',
            'phone' => '01000000001',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['user_id', 'email'], 'message', 'errors'])
            ->assertJson([
                'success' => true,
                'message' => 'Account created, call send-otp to receive a verification code',
                'errors' => null,
            ])
            ->assertJsonPath('data.email', 'jane.player@example.com');

        $user = User::where('email', 'jane.player@example.com')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('player'));
        $this->assertNull($user->email_verified_at);
        $this->assertSame('active', $user->status);
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
