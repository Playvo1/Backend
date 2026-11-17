<?php

namespace Tests\Feature;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

/**
 * Private channel auth for the token-based apps: POST /api/broadcasting/auth with a Bearer token.
 * Uses the pusher driver with dummy credentials; signing an auth response needs no network.
 */
class BroadcastingAuthTest extends TestCase
{
    use BuildsPlayvoData;
    use RefreshDatabase;

    private const URL = '/api/broadcasting/auth';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
            'broadcasting.connections.pusher.options.cluster' => 'eu',
        ]);
        $this->app->make(BroadcastManager::class)->purge('pusher');
        require base_path('routes/channels.php');
    }

    private function auth(string $token, int $userId)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson(self::URL, [
            'socket_id' => '1234.5678',
            'channel_name' => "private-App.Models.User.{$userId}",
        ]);
    }

    public function test_user_can_subscribe_to_their_own_channel(): void
    {
        $user = $this->userWithRole('admin');
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->auth($token, $user->id)->assertOk();

        $this->assertStringStartsWith('test-key:', $response->json('auth'));
    }

    public function test_another_users_channel_is_forbidden(): void
    {
        $user = $this->userWithRole('venue_owner');
        $other = $this->userWithRole('admin');
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->auth($token, $other->id)->assertStatus(403);
    }

    public function test_request_without_a_token_is_unauthorised(): void
    {
        $user = $this->userWithRole('player');

        $this->postJson(self::URL, [
            'socket_id' => '1234.5678',
            'channel_name' => "private-App.Models.User.{$user->id}",
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
