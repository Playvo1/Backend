<?php

namespace Tests\Feature\Admin;

use App\Mail\TemporaryPasswordMail;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CreateAdminTest extends TestCase
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

    public function test_admin_can_create_another_admin(): void
    {
        Mail::fake();

        [$admin, $token] = $this->authenticateAsAdmin();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/admins', [
                'name' => 'Admin Two',
                'email' => 'admin2@example.com',
                'phone' => '01000000020',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success', 'data' => ['user_id', 'name', 'email', 'roles'], 'message', 'errors',
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Admin created, credentials sent by email',
                'errors' => null,
            ])
            ->assertJsonPath('data.email', 'admin2@example.com')
            ->assertJsonPath('data.roles', ['admin']);

        $newAdmin = User::where('email', 'admin2@example.com')->first();

        $this->assertNotNull($newAdmin);
        $this->assertTrue($newAdmin->hasRole('admin'));

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin_created',
            'target_type' => 'USER',
            'target_id' => $newAdmin->id,
        ]);

        Mail::assertSent(TemporaryPasswordMail::class, fn ($mail) => $mail->hasTo($newAdmin->email));
    }

    public function test_admin_creation_fails_for_a_duplicate_email(): void
    {
        Mail::fake();

        [, $token] = $this->authenticateAsAdmin();

        User::factory()->create(['email' => 'dupe-admin@example.com']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/admins', [
                'name' => 'Admin Three',
                'email' => 'dupe-admin@example.com',
                'phone' => '01000000021',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonValidationErrors(['email']);

        Mail::assertNothingSent();
    }

    public function test_admin_creation_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/admin/admins', [
            'name' => 'Admin Four',
            'email' => 'admin4@example.com',
            'phone' => '01000000022',
        ]);

        $response->assertStatus(401)
            ->assertJsonStructure(['success', 'data', 'message', 'errors'])
            ->assertJson(['success' => false, 'data' => null]);
    }

    public function test_admin_creation_is_forbidden_for_non_admin_roles(): void
    {
        $venueOwner = User::factory()->create();
        $venueOwner->assignRole('venue_owner');

        $token = $venueOwner->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/admins', [
                'name' => 'Admin Five',
                'email' => 'admin5@example.com',
                'phone' => '01000000023',
            ]);

        $response->assertStatus(403)
            ->assertJsonStructure(['success', 'data', 'message', 'errors'])
            ->assertJson(['success' => false, 'data' => null]);
    }
}
