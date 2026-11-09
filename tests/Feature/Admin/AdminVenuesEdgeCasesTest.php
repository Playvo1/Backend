<?php

namespace Tests\Feature\Admin;

use App\Mail\TemporaryPasswordMail;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Image;
use App\Models\Sport;
use App\Models\User;
use App\Models\Venue;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

/**
 * Edge cases of the admin venue endpoints: races on the owner and email unique indexes,
 * the credentials email failing after commit, protected fields, form-style payloads,
 * LIKE escaping, photos and the admin profile.
 */
class AdminVenuesEdgeCasesTest extends TestCase
{
    use BuildsPlayvoData;
    use RefreshDatabase;

    // A valid 1x1 PNG.
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $admin;

    private City $city;

    private Sport $football;

    /** @var list<string> */
    private array $uploaded = [];

    /** @var list<string> */
    private array $destroyed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Mail::fake();

        $this->city = $this->makeCity();
        $this->football = $this->makeSport();
        $this->admin = $this->actingAsRole('admin');

        $api = Mockery::mock();
        $api->shouldReceive('upload')->andReturnUsing(function () {
            $n = count($this->uploaded) + 1;
            $url = "https://res.cloudinary.com/demo/image/upload/v1/playvo/venues/photo{$n}.png";
            $this->uploaded[] = $url;

            return ['secure_url' => $url, 'public_id' => "playvo/venues/photo{$n}"];
        });
        $api->shouldReceive('destroy')->andReturnUsing(function (string $publicId) {
            $this->destroyed[] = $publicId;

            return ['result' => 'ok'];
        });

        $cloudinary = Mockery::mock();
        $cloudinary->shouldReceive('uploadApi')->andReturn($api);
        $this->instance(\Cloudinary\Cloudinary::class, $cloudinary);
        Cloudinary::clearResolvedInstance(\Cloudinary\Cloudinary::class);
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png').'.png';
        file_put_contents($path, base64_decode(self::PNG));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name_ar' => 'ملعب غزة الرياضي',
            'city_id' => $this->city->id,
            'sport_id' => $this->football->id,
            'owner_name' => 'Owner One',
            'owner_email' => 'owner.one@example.com',
            'owner_phone' => '0599000000',
            'owner_password' => 'Temp-pass-123',
        ], $overrides);
    }

    // ---- Races on unique indexes ----

    /**
     * Two admins create a venue for the same existing owner at once: both pass validation,
     * the second insert hits venues_owner_id_unique. That must be a clear client error, not a 500.
     */
    public function test_concurrent_create_for_the_same_owner_is_not_a_server_error(): void
    {
        $owner = $this->userWithRole('venue_owner');

        // The competing request commits between this request's validation and its insert.
        Venue::creating(function (Venue $venue) use ($owner) {
            if ((int) $venue->owner_id === $owner->id && !Venue::withTrashed()->where('owner_id', $owner->id)->exists()) {
                DB::table('venues')->insert([
                    'owner_id' => $owner->id,
                    'city_id' => $this->city->id,
                    'name_ar' => 'منافس',
                    'name_en' => 'Competitor',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $response = $this->post('/api/v1/admin/venues', [
            'owner_id' => $owner->id,
            'name_ar' => 'ملعب',
            'city_id' => $this->city->id,
            'images' => [$this->png()],
        ], ['Accept' => 'application/json']);

        $this->assertContains($response->status(), [409, 422], 'Got '.$response->status());
        $this->assertSame(1, Venue::withTrashed()->count());
        $this->assertSame(0, Image::count());
        $this->assertSame(['playvo/venues/photo1'], $this->destroyed);
    }

    /**
     * Two admins create an owner with the same email at once: the second insert hits the
     * users.email unique index after validation passed.
     */
    public function test_concurrent_create_with_the_same_new_owner_email_is_not_a_server_error(): void
    {
        User::creating(function (User $user) {
            if ($user->email === 'owner.one@example.com' && !User::where('email', $user->email)->exists()) {
                DB::table('users')->insert([
                    'name' => 'Other admin\'s owner',
                    'email' => 'owner.one@example.com',
                    'password' => Hash::make('x-password-1'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $response = $this->postJson('/api/v1/admin/venues', $this->payload());

        $this->assertContains($response->status(), [409, 422], 'Got '.$response->status());
        $this->assertSame(0, Venue::count());
        $this->assertSame(0, AuditLog::count());
    }

    // ---- Credentials email ----

    /**
     * The venue and the owner are committed before the credentials email is sent. If sending
     * fails the admin must not be told that creation failed (a retry would then be rejected
     * with "email already in use"); the venue exists and credentials_emailed is false.
     */
    public function test_credentials_email_failure_after_commit_does_not_report_a_failed_create(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

        $response = $this->postJson('/api/v1/admin/venues', $this->payload(['owner_password' => null]));

        $this->assertSame(1, Venue::count());
        $this->assertNotSame(500, $response->status(), 'Venue was created but the response says it failed.');
        $response->assertCreated()->assertJsonPath('data.owner_account.credentials_emailed', false);
    }

    // ---- Protected fields ----

    public function test_status_rating_owner_and_ids_cannot_be_set_on_create(): void
    {
        $this->postJson('/api/v1/admin/venues', $this->payload([
            'status' => 'inactive',
            'avg_rating' => 5,
            'id' => 999,
            'deleted_at' => now()->toDateTimeString(),
            'created_at' => '2020-01-01 00:00:00',
        ]))->assertCreated()->assertJsonPath('data.status', 'active');

        $venue = Venue::withTrashed()->firstOrFail();
        $this->assertNotSame(999, $venue->id);
        $this->assertNull($venue->deleted_at);
        $this->assertEquals(0, $venue->avg_rating);
        $this->assertNotSame('2020', $venue->created_at->format('Y'));
    }

    public function test_new_owner_cannot_be_given_another_role_or_status(): void
    {
        $this->postJson('/api/v1/admin/venues', $this->payload([
            'owner_role' => 'admin',
            'role' => 'admin',
            'owner_status' => 'inactive',
        ]))->assertCreated();

        $owner = User::where('email', 'owner.one@example.com')->firstOrFail();
        $this->assertSame(['venue_owner'], $owner->getRoleNames()->all());
        $this->assertSame('active', $owner->status);
    }

    // ---- Form-style (multipart) payloads ----

    public function test_multipart_form_with_empty_owner_id_creates_a_new_owner(): void
    {
        $this->post('/api/v1/admin/venues', $this->payload([
            'owner_id' => '',
            'owner_phone' => '0599000000',
            'name_en' => '',
            'length_m' => '40',
            'width_m' => '20',
            'images' => [$this->png('a.png'), $this->png('b.png')],
        ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.owner_account.created', true)
            ->assertJsonPath('data.name_en', 'ملعب غزة الرياضي')
            ->assertJsonPath('data.images_count', 2)
            ->assertJsonPath('data.cover_image_url', 'https://res.cloudinary.com/demo/image/upload/v1/playvo/venues/photo1.png');
    }

    public function test_existing_owner_with_empty_new_owner_fields_is_accepted(): void
    {
        $owner = $this->userWithRole('venue_owner');

        $this->post('/api/v1/admin/venues', [
            'owner_id' => (string) $owner->id,
            'owner_name' => '',
            'owner_email' => '',
            'owner_password' => '',
            'name_ar' => 'ملعب',
            'city_id' => (string) $this->city->id,
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.owner.id', $owner->id);
    }

    public function test_sport_ids_take_precedence_over_sport_id(): void
    {
        $padel = $this->makeSport('Padel', 'بادل');

        $this->postJson('/api/v1/admin/venues', $this->payload([
            'sport_id' => $this->football->id,
            'sport_ids' => [$padel->id],
        ]))->assertCreated()
            ->assertJsonCount(1, 'data.sports')
            ->assertJsonPath('data.sports.0.id', $padel->id);
    }

    public function test_short_password_and_bad_city_are_rejected_before_any_upload(): void
    {
        $this->post('/api/v1/admin/venues', $this->payload([
            'owner_password' => 'short',
            'city_id' => 999999,
            'images' => [$this->png()],
        ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner_password', 'city_id']);

        $this->assertSame([], $this->uploaded);
        $this->assertSame(1, User::count());
    }

    // ---- List search ----

    public function test_search_treats_exclamation_mark_and_underscore_literally(): void
    {
        $bang = $this->makeVenue(null, ['name_en' => 'Goal! Arena']);
        $under = $this->makeVenue(null, ['name_en' => 'Field_7']);
        $this->makeVenue(null, ['name_en' => 'Field 7']);

        $ids = fn (string $search) => collect($this->getJson('/api/v1/admin/venues?search='.urlencode($search))->assertOk()->json('data.data'))
            ->pluck('id')->all();

        $this->assertSame([$bang->id], $ids('!'));
        $this->assertSame([$bang->id], $ids('Goal!'));
        $this->assertSame([$under->id], $ids('Field_'));
    }

    public function test_list_cover_url_is_absolute_for_a_local_path_and_null_without_photos(): void
    {
        $withPhoto = $this->makeVenue(null, ['name_en' => 'With photo']);
        $withPhoto->images()->create(['image_url' => '/storage/venues/a.jpg', 'sort_order' => 0]);
        $this->makeVenue(null, ['name_en' => 'No photo']);

        $items = collect($this->getJson('/api/v1/admin/venues')->assertOk()->json('data.data'))->keyBy('name_en');

        $this->assertSame(url('/storage/venues/a.jpg'), $items['With photo']['cover_image_url']);
        $this->assertNull($items['No photo']['cover_image_url']);
    }

    // ---- Photos on an existing venue ----

    public function test_added_photos_keep_the_existing_cover_and_continue_the_order(): void
    {
        $venue = $this->makeVenue();
        $venue->images()->create(['image_url' => 'https://res.cloudinary.com/x/old.jpg', 'sort_order' => 4, 'is_cover' => true]);

        $this->post("/api/v1/admin/venues/{$venue->id}/images", ['images' => [$this->png(), $this->png()]], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.is_cover', true)
            ->assertJsonPath('data.1.sort_order', 5)
            ->assertJsonPath('data.1.is_cover', false)
            ->assertJsonPath('data.2.sort_order', 6);
    }

    public function test_photos_cannot_be_added_to_a_removed_venue(): void
    {
        $venue = $this->makeVenue();
        $venue->delete();

        $this->post("/api/v1/admin/venues/{$venue->id}/images", ['images' => [$this->png()]], ['Accept' => 'application/json'])
            ->assertNotFound();

        $this->assertSame([], $this->uploaded);
    }

    // ---- Access ----

    public function test_unauthenticated_requests_get_401_on_every_admin_route(): void
    {
        $venue = $this->makeVenue();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/admin/venues')->assertUnauthorized();
        $this->getJson("/api/v1/admin/venues/{$venue->id}")->assertUnauthorized();
        $this->postJson('/api/v1/admin/venues', $this->payload())->assertUnauthorized();
        $this->postJson('/api/v1/admin/admin/venues', $this->payload())->assertUnauthorized();
        $this->postJson("/api/v1/admin/venues/{$venue->id}/images", [])->assertUnauthorized();
        $this->getJson('/api/v1/admin/profile')->assertUnauthorized();
        $this->putJson('/api/v1/admin/profile', ['name' => 'X'])->assertUnauthorized();

        $this->assertSame(1, Venue::count());
    }

    public function test_reference_lists_are_public_and_validate_country_id(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/countries')->assertOk()->assertJsonPath('data.0.name_en', 'Palestine');
        $this->getJson('/api/v1/sports')->assertOk()->assertJsonPath('data.0.id', $this->football->id);
        $this->getJson('/api/v1/cities?country_id=abc')->assertStatus(422);
        $this->getJson('/api/v1/cities?country_id=999999')->assertOk()->assertJsonPath('data', []);
    }

    // ---- Admin profile ----

    public function test_admin_profile_update_ignores_status_password_and_role(): void
    {
        $passwordHash = $this->admin->password;

        $this->putJson('/api/v1/admin/profile', [
            'name' => 'New Name',
            'status' => 'inactive',
            'password' => 'hijacked-password',
            'email_verified_at' => null,
            'role' => 'player',
        ])->assertOk()->assertJsonPath('data.name', 'New Name');

        $admin = $this->admin->fresh();
        $this->assertSame('active', $admin->status);
        $this->assertSame($passwordHash, $admin->password);
        $this->assertTrue($admin->hasRole('admin'));
    }

    public function test_admin_profile_rejects_another_users_email_and_empty_name(): void
    {
        $other = $this->userWithRole('player');

        $this->putJson('/api/v1/admin/profile', ['email' => $other->email, 'name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'name']);
    }

    // ---- Existing account endpoints on the shared provisioner ----

    public function test_create_venue_owner_endpoint_still_emails_and_audits(): void
    {
        $this->postJson('/api/v1/admin/venue-owners', [
            'name' => 'Owner Two',
            'email' => 'owner.two@example.com',
            'phone' => '0599000000',
        ])->assertSuccessful();

        $owner = User::where('email', 'owner.two@example.com')->firstOrFail();
        $this->assertTrue($owner->hasRole('venue_owner'));
        $this->assertSame('active', $owner->status);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'venue_owner_created',
            'target_type' => 'USER',
            'target_id' => $owner->id,
        ]);
        Mail::assertSent(TemporaryPasswordMail::class, fn ($mail) => $mail->hasTo('owner.two@example.com'));
    }
}
