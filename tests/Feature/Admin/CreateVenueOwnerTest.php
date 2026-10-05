<?php

namespace Tests\Feature\Admin;

use App\Mail\TemporaryPasswordMail;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CreateVenueOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function authenticateAsAdmin(): array
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $token = $admin->createToken('test')->plainTextToken;

        return [$admin, $token];
    }

    public function test_admin_can_create_a_venue_owner(): void
    {
        Mail::fake();

        [$admin, $token] = $this->authenticateAsAdmin();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/venue-owners', [
                'name' => 'Owner One',
                'email' => 'owner1@example.com',
                'phone' => '01000000010',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success', 'data' => ['user_id', 'name', 'email', 'roles'], 'message', 'errors',
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Venue owner created, credentials sent by email',
                'errors' => null,
            ])
            ->assertJsonPath('data.email', 'owner1@example.com')
            ->assertJsonPath('data.roles', ['venue_owner']);

        $newUser = User::where('email', 'owner1@example.com')->first();

        $this->assertNotNull($newUser);
        $this->assertTrue($newUser->hasRole('venue_owner'));
        $this->assertSame('active', $newUser->status);
        $this->assertNotNull($newUser->email_verified_at);

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'venue_owner_created',
            'target_type' => 'USER',
            'target_id' => $newUser->id,
        ]);

        Mail::assertSent(TemporaryPasswordMail::class, fn ($mail) => $mail->hasTo($newUser->email));
    }

    public function test_venue_owner_creation_fails_for_a_duplicate_email(): void
    {
        Mail::fake();

        [, $token] = $this->authenticateAsAdmin();

        User::factory()->create(['email' => 'duplicate@example.com']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/venue-owners', [
                'name' => 'Owner Two',
                'email' => 'duplicate@example.com',
                'phone' => '01000000011',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonValidationErrors(['email']);

        Mail::assertNothingSent();
    }

    public function test_venue_owner_creation_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/admin/venue-owners', [
            'name' => 'Owner Three',
            'email' => 'owner3@example.com',
            'phone' => '01000000012',
        ]);

        $response->assertStatus(401)
            ->assertJsonStructure(['success', 'data', 'message', 'errors'])
            ->assertJson(['success' => false, 'data' => null]);
    }

    public function test_venue_owner_creation_is_forbidden_for_non_admin_roles(): void
    {
        $player = User::factory()->create();
        $player->assignRole('player');

        $token = $player->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/venue-owners', [
                'name' => 'Owner Four',
                'email' => 'owner4@example.com',
                'phone' => '01000000013',
            ]);

        $response->assertStatus(403)
            ->assertJsonStructure(['success', 'data', 'message', 'errors'])
            ->assertJson(['success' => false, 'data' => null]);
    }
}
