<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The success path (a genuinely valid Google ID token) can't be exercised here
 * without either hitting Google's cert endpoint over the network or refactoring
 * the controller to accept an injectable client, which is out of scope for test
 * authoring alone. Coverage below is limited to the failure paths that fail
 * locally with no network dependency: request validation and a syntactically
 * invalid token.
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_auth_fails_validation_when_id_token_missing(): void
    {
        $response = $this->postJson('/api/v1/auth/google', []);

        $response->assertStatus(422)
            ->assertJsonStructure(['success', 'data', 'message', 'errors' => ['id_token']])
            ->assertJson(['success' => false, 'data' => null]);
    }

    public function test_google_auth_rejects_a_malformed_token_without_a_network_call(): void
    {
        // Not a well-formed JWT (wrong number of '.' separated segments), so the
        // underlying library rejects it before ever attempting to fetch Google's
        // signing certs.
        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'this-is-not-a-jwt',
        ]);

        $response->assertStatus(401)
            ->assertJsonStructure(['success', 'data', 'message', 'errors'])
            ->assertJson([
                'success' => false,
                'data' => null,
                'message' => 'Invalid Google token.',
                'errors' => null,
            ]);
    }
}
