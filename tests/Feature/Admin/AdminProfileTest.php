<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use BuildsPlayvoData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_profile_shows_status_and_roles(): void
    {
        $admin = $this->actingAsRole('admin');

        $this->getJson('/api/v1/admin/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.email', $admin->email)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.roles', ['admin'])
            ->assertJsonPath('data.profile_image', null);
    }

    public function test_admin_can_update_name_email_and_phone(): void
    {
        $admin = $this->actingAsRole('admin');

        $this->putJson('/api/v1/admin/profile', [
            'name' => 'Main Admin',
            'email' => 'main.admin@example.com',
            'phone' => '0599111222',
        ])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Admin profile updated successfully.',
                'data' => [
                    'id' => $admin->id,
                    'name' => 'Main Admin',
                    'email' => 'main.admin@example.com',
                    'phone' => '0599111222',
                ],
                'errors' => null,
            ]);

        $this->assertSame('main.admin@example.com', $admin->fresh()->email);
    }

    public function test_partial_update_keeps_other_fields_and_own_email_is_allowed(): void
    {
        $admin = $this->actingAsRole('admin');
        $email = $admin->email;

        $this->putJson('/api/v1/admin/profile', ['name' => 'Renamed', 'email' => $email])->assertOk();

        $fresh = $admin->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame($email, $fresh->email);
    }

    public function test_email_must_be_unique_and_fields_valid(): void
    {
        $other = $this->userWithRole('player');
        $this->actingAsRole('admin');

        $this->putJson('/api/v1/admin/profile', ['email' => $other->email])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->putJson('/api/v1/admin/profile', ['name' => '', 'email' => 'not-an-email', 'phone' => str_repeat('1', 31)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'phone']);
    }

    public function test_non_admin_gets_403(): void
    {
        foreach (['player', 'venue_owner'] as $role) {
            $this->actingAsRole($role);

            $this->getJson('/api/v1/admin/profile')->assertStatus(403);
            $this->putJson('/api/v1/admin/profile', ['name' => 'X'])->assertStatus(403);
        }
    }
}
