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
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class AdminVenuesTest extends TestCase
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

    private ?int $failUploadAt = null;

    /** Runs during each upload, to simulate a competing request. */
    private ?\Closure $duringUpload = null;

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

            if ($this->failUploadAt === $n) {
                throw new RuntimeException('Cloudinary is down');
            }

            if ($this->duringUpload) {
                ($this->duringUpload)();
            }

            $url = "https://res.cloudinary.com/demo/image/upload/v1/playvo/venues/photo{$n}.png";
            $this->uploaded[] = $url;

            return ['secure_url' => $url, 'public_id' => "playvo/venues/photo{$n}"];
        });
        $api->shouldReceive('destroy')->andReturnUsing(function (string $publicId) {
            $this->destroyed[] = $publicId;

            return ['result' => 'ok'];
        });

        // The facade resolves this container binding, so no Cloudinary credentials are needed.
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

    private function pngs(int $count): array
    {
        return array_map(fn (int $i) => $this->png("photo{$i}.png"), range(1, $count));
    }

    private function newOwnerPayload(array $overrides = []): array
    {
        return array_merge([
            'name_ar' => 'ملعب غزة الرياضي',
            'name_en' => 'Gaza Sports Field',
            'city_id' => $this->city->id,
            'address_ar' => 'شارع الوحدة',
            'description_ar' => 'ملعب خماسي',
            'length_m' => 40,
            'width_m' => 20,
            'sport_id' => $this->football->id,
            'owner_name' => 'Owner One',
            'owner_email' => 'owner.one@example.com',
            'owner_phone' => '0599000000',
            'owner_password' => 'Temp-pass-123',
        ], $overrides);
    }

    private function assertNothingCreated(int $venues = 0, int $users = null): void
    {
        $this->assertSame($venues, Venue::withTrashed()->count());
        $this->assertSame(0, Image::count());

        if ($users !== null) {
            $this->assertSame($users, User::count());
        }
    }

    // ---- List ----

    public function test_list_shows_venues_with_city_owner_and_cover(): void
    {
        $owner = $this->userWithRole('venue_owner');
        $venue = $this->makeVenue($owner, ['address_ar' => 'شارع الوحدة', 'area_ar' => 'الرمال']);
        $venue->images()->create(['image_url' => '/storage/venues/b.jpg', 'sort_order' => 2]);
        $venue->images()->create(['image_url' => 'https://res.cloudinary.com/x/a.jpg', 'sort_order' => 1]);

        $this->getJson('/api/v1/admin/venues')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.per_page', 10)
            ->assertJsonPath('data.data.0', [
                'id' => $venue->id,
                'name_ar' => 'ملعب الحقل الأخضر',
                'name_en' => 'Green Field Court',
                'status' => 'active',
                'city' => ['id' => $this->city->id, 'name_ar' => 'غزة', 'name_en' => 'Gaza'],
                'address_ar' => 'شارع الوحدة',
                'address_en' => null,
                'area_ar' => 'الرمال',
                'area_en' => null,
                'owner' => ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email],
                'cover_image_url' => 'https://res.cloudinary.com/x/a.jpg',
                'created_at' => $venue->created_at->toJSON(),
            ]);
    }

    public function test_list_filters_by_search_city_and_status_and_hides_deleted(): void
    {
        $other = $this->makeCity('Rafah', 'رفح');
        $a = $this->makeVenue(null, ['name_en' => 'Alpha 50% Arena', 'area_en' => 'Rimal']);
        $b = $this->makeVenue(null, ['name_en' => 'Beta', 'city_id' => $other->id, 'status' => 'inactive']);
        $c = $this->makeVenue(null, ['name_en' => 'Gamma', 'address_ar' => 'شارع عمر المختار']);
        $deleted = $this->makeVenue(null, ['name_en' => 'Deleted Alpha']);
        $deleted->delete();

        $ids = fn (string $query) => collect($this->getJson('/api/v1/admin/venues'.$query)->assertOk()->json('data.data'))
            ->pluck('id')->sort()->values()->all();

        $this->assertSame([$a->id, $b->id, $c->id], $ids(''));
        $this->assertSame([$a->id], $ids('?search=alpha'));
        $this->assertSame([$a->id], $ids('?search=rimal'));
        $this->assertSame([$c->id], $ids('?search='.urlencode('عمر')));
        $this->assertSame([$a->id], $ids('?search='.urlencode('50%')));
        $this->assertSame([$a->id], $ids('?search='.urlencode('%')));
        $this->assertSame([], $ids('?search=_'));
        $this->assertSame([$b->id], $ids("?city_id={$other->id}"));
        $this->assertSame([$b->id], $ids('?status=inactive'));
        $this->assertSame([$a->id, $c->id], $ids('?status=active'));

        $this->getJson('/api/v1/admin/venues?status=deleted')->assertStatus(422);
        $this->getJson('/api/v1/admin/venues?per_page=101')->assertStatus(422);
    }

    public function test_list_paginates(): void
    {
        foreach (range(1, 12) as $i) {
            $this->makeVenue(null, ['name_en' => "Venue {$i}"]);
        }

        $this->getJson('/api/v1/admin/venues')
            ->assertJsonPath('data.total', 12)
            ->assertJsonCount(10, 'data.data')
            ->assertJsonPath('data.last_page', 2);

        $this->getJson('/api/v1/admin/venues?per_page=5&page=3')
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.current_page', 3);
    }

    public function test_list_query_count_does_not_grow_with_venues(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/admin/venues')->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $venue = $this->makeVenue();
        $venue->images()->create(['image_url' => 'https://res.cloudinary.com/x/a.jpg', 'sort_order' => 0]);
        $count();
        $few = $count();

        foreach (range(1, 8) as $i) {
            $v = $this->makeVenue();
            $v->images()->create(['image_url' => "https://res.cloudinary.com/x/{$i}.jpg", 'sort_order' => 0]);
        }

        $this->assertSame($few, $count());
    }

    // ---- Details ----

    public function test_details_return_everything_the_screen_shows(): void
    {
        $owner = $this->userWithRole('venue_owner');
        $venue = $this->makeVenue($owner, [
            'address_ar' => 'شارع الوحدة',
            'length_m' => 40,
            'width_m' => 20,
            'surface_ar' => 'عشب صناعي',
            'team_format_ar' => '5 في 5',
            'capacity_ar' => '10 لاعبين',
            'description_ar' => 'وصف',
            'morning_hourly_price' => 75,
            'evening_hourly_price' => 140,
        ]);
        $venue->sports()->attach($this->football->id);
        $second = $venue->images()->create(['image_url' => '/storage/venues/b.jpg', 'sort_order' => 1, 'is_cover' => true]);
        $first = $venue->images()->create(['image_url' => 'https://res.cloudinary.com/x/a.jpg', 'sort_order' => 0]);

        $this->getJson("/api/v1/admin/venues/{$venue->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $venue->id)
            ->assertJsonPath('data.name_ar', 'ملعب الحقل الأخضر')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.city.name_en', 'Gaza')
            ->assertJsonPath('data.country.name_en', 'Palestine')
            ->assertJsonPath('data.sports.0.name_en', 'Football')
            ->assertJsonPath('data.length_m', 40)
            ->assertJsonPath('data.width_m', 20)
            ->assertJsonPath('data.surface_ar', 'عشب صناعي')
            ->assertJsonPath('data.team_format_ar', '5 في 5')
            ->assertJsonPath('data.capacity_ar', '10 لاعبين')
            ->assertJsonPath('data.description_ar', 'وصف')
            ->assertJsonPath('data.morning_hourly_price', 75)
            ->assertJsonPath('data.evening_hourly_price', 140)
            ->assertJsonPath('data.owner', [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'phone' => $owner->phone,
                'status' => 'active',
            ])
            ->assertJsonPath('data.images', [
                ['id' => $first->id, 'image_url' => 'https://res.cloudinary.com/x/a.jpg', 'is_cover' => false, 'sort_order' => 0],
                ['id' => $second->id, 'image_url' => url('/storage/venues/b.jpg'), 'is_cover' => true, 'sort_order' => 1],
            ])
            ->assertJsonPath('data.cover_image_url', url('/storage/venues/b.jpg'))
            ->assertJsonPath('data.images_count', 2);
    }

    public function test_details_404_for_missing_or_deleted_venue(): void
    {
        $this->getJson('/api/v1/admin/venues/999')->assertStatus(404)->assertJsonPath('success', false);

        $venue = $this->makeVenue();
        $venue->delete();
        $this->getJson("/api/v1/admin/venues/{$venue->id}")->assertStatus(404);
    }

    // ---- Create ----

    public function test_create_with_new_owner_creates_account_venue_and_audit(): void
    {
        $response = $this->postJson('/api/v1/admin/venues', $this->newOwnerPayload())
            ->assertCreated()
            ->assertJsonPath('data.name_ar', 'ملعب غزة الرياضي')
            ->assertJsonPath('data.name_en', 'Gaza Sports Field')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.city.id', $this->city->id)
            ->assertJsonPath('data.sports.0.id', $this->football->id)
            ->assertJsonPath('data.length_m', 40)
            ->assertJsonPath('data.owner.email', 'owner.one@example.com')
            ->assertJsonPath('data.owner_account.email', 'owner.one@example.com')
            ->assertJsonPath('data.owner_account.created', true)
            ->assertJsonPath('data.owner_account.credentials_emailed', false)
            ->assertJsonPath('data.images', []);

        $this->assertStringNotContainsString('Temp-pass-123', $response->getContent());

        $owner = User::where('email', 'owner.one@example.com')->firstOrFail();
        $this->assertTrue($owner->hasRole('venue_owner'));
        $this->assertSame('active', $owner->status);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertSame($owner->id, Venue::firstOrFail()->owner_id);

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'venue_owner_created',
            'target_type' => 'USER',
            'target_id' => $owner->id,
        ]);

        // A typed password is not emailed.
        Mail::assertNothingSent();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'owner.one@example.com', 'password' => 'Temp-pass-123'])
            ->assertOk();
    }

    public function test_generated_password_is_emailed_when_none_is_typed(): void
    {
        $this->postJson('/api/v1/admin/venues', $this->newOwnerPayload(['owner_password' => null]))
            ->assertCreated()
            ->assertJsonPath('data.owner_account.credentials_emailed', true);

        Mail::assertSent(TemporaryPasswordMail::class, fn ($mail) => $mail->hasTo('owner.one@example.com'));
    }

    public function test_name_en_falls_back_to_name_ar_and_default_sport_is_football(): void
    {
        $this->postJson('/api/v1/admin/venues', $this->newOwnerPayload(['name_en' => null, 'sport_id' => null]))
            ->assertCreated()
            ->assertJsonPath('data.name_en', 'ملعب غزة الرياضي')
            ->assertJsonPath('data.sports.0.id', $this->football->id);
    }

    public function test_create_with_existing_owner(): void
    {
        $owner = $this->userWithRole('venue_owner');

        $this->postJson('/api/v1/admin/venues', [
            'owner_id' => $owner->id,
            'name_ar' => 'ملعب',
            'city_id' => $this->city->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.owner_account.created', false);

        $this->assertSame(0, AuditLog::count());
    }

    public function test_owner_who_already_has_a_venue_is_rejected_and_nothing_created(): void
    {
        $owner = $this->userWithRole('venue_owner');
        $this->makeVenue($owner)->delete();

        $this->postJson('/api/v1/admin/venues', [
            'owner_id' => $owner->id,
            'name_ar' => 'ملعب',
            'city_id' => $this->city->id,
            'images' => $this->pngs(2),
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.owner_id.0', 'The selected owner already has a venue.');

        $this->assertNothingCreated(1);
        $this->assertSame([], $this->uploaded);
    }

    public function test_user_who_is_not_a_venue_owner_is_rejected_and_nothing_created(): void
    {
        $player = $this->userWithRole('player');

        $this->postJson('/api/v1/admin/venues', [
            'owner_id' => $player->id,
            'name_ar' => 'ملعب',
            'city_id' => $this->city->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.owner_id.0', 'The selected user is not a venue owner.');

        $this->assertNothingCreated();
    }

    public function test_both_or_neither_owner_modes_are_rejected(): void
    {
        $owner = $this->userWithRole('venue_owner');
        $users = User::count();

        $this->postJson('/api/v1/admin/venues', $this->newOwnerPayload(['owner_id' => $owner->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner_id']);

        $this->postJson('/api/v1/admin/venues', [
            'name_ar' => 'ملعب',
            'city_id' => $this->city->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner_id', 'owner_name', 'owner_email']);

        $this->assertNothingCreated(0, $users);
    }

    public function test_new_owner_needs_a_phone(): void
    {
        $this->postJson('/api/v1/admin/venues', $this->newOwnerPayload(['owner_phone' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner_phone']);

        $this->assertNothingCreated();
    }

    public function test_new_owner_fields_are_validated(): void
    {
        $existing = $this->userWithRole('player');

        $this->postJson('/api/v1/admin/venues', $this->newOwnerPayload([
            'owner_email' => $existing->email,
            'owner_password' => 'short',
            'name_ar' => '',
            'city_id' => 999,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner_email', 'owner_password', 'name_ar', 'city_id']);

        $this->assertNothingCreated();
    }

    public function test_up_to_six_images_are_uploaded_and_the_first_is_cover(): void
    {
        $response = $this->post('/api/v1/admin/venues', $this->newOwnerPayload(['images' => $this->pngs(6)]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(6, 'data.images')
            ->assertJsonPath('data.images.0.is_cover', true)
            ->assertJsonPath('data.images.1.is_cover', false)
            ->assertJsonPath('data.images.0.sort_order', 0)
            ->assertJsonPath('data.images.5.sort_order', 5)
            ->assertJsonPath('data.cover_image_url', $this->uploaded[0]);

        $this->assertSame($this->uploaded, collect($response->json('data.images'))->pluck('image_url')->all());
    }

    public function test_seven_images_are_rejected(): void
    {
        $this->post('/api/v1/admin/venues', $this->newOwnerPayload(['images' => $this->pngs(7)]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);

        $this->assertSame([], $this->uploaded);
        $this->assertNothingCreated();
    }

    public function test_non_image_file_is_rejected(): void
    {
        $text = UploadedFile::fake()->create('notes.txt', 1, 'text/plain');

        $this->post('/api/v1/admin/venues', $this->newOwnerPayload(['images' => [$text]]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);
    }

    public function test_upload_failure_deletes_uploaded_images_and_creates_nothing(): void
    {
        $users = User::count();
        $this->failUploadAt = 3;

        $this->post('/api/v1/admin/venues', $this->newOwnerPayload(['images' => $this->pngs(4)]), ['Accept' => 'application/json'])
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->assertSame(['playvo/venues/photo1', 'playvo/venues/photo2'], $this->destroyed);
        $this->assertNothingCreated(0, $users);
    }

    public function test_database_failure_rolls_back_and_deletes_uploaded_images(): void
    {
        $users = User::count();
        Venue::created(fn () => throw new RuntimeException('database failure'));

        $this->post('/api/v1/admin/venues', $this->newOwnerPayload(['images' => $this->pngs(2)]), ['Accept' => 'application/json'])
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->assertSame(['playvo/venues/photo1', 'playvo/venues/photo2'], $this->destroyed);
        $this->assertNothingCreated(0, $users);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_old_create_path_still_works(): void
    {
        $owner = $this->userWithRole('venue_owner');

        $this->postJson('/api/v1/admin/admin/venues', [
            'owner_id' => $owner->id,
            'name_ar' => 'ملعب',
            'name_en' => 'Field',
            'city_id' => $this->city->id,
            'min_hourly_price' => 100,
            'sport_ids' => [$this->football->id],
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name_en', 'Field')
            ->assertJsonPath('data.min_hourly_price', 100);
    }

    // ---- Add images to an existing venue ----

    public function test_admin_can_add_images_to_an_existing_venue(): void
    {
        $venue = $this->makeVenue();

        $this->post("/api/v1/admin/venues/{$venue->id}/images", ['images' => $this->pngs(2)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_cover', true)
            ->assertJsonPath('data.1.is_cover', false);

        $this->post("/api/v1/admin/venues/{$venue->id}/images", ['images' => $this->pngs(1)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.is_cover', false)
            ->assertJsonPath('data.2.sort_order', 2);
    }

    public function test_adding_images_respects_the_six_photo_limit(): void
    {
        $venue = $this->makeVenue();
        foreach (range(0, 4) as $i) {
            $venue->images()->create(['image_url' => "https://res.cloudinary.com/x/{$i}.jpg", 'sort_order' => $i]);
        }

        $this->post("/api/v1/admin/venues/{$venue->id}/images", ['images' => $this->pngs(2)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);

        $this->assertSame(5, $venue->images()->count());
        $this->assertSame([], $this->uploaded);

        $this->post("/api/v1/admin/venues/{$venue->id}/images", ['images' => $this->pngs(1)], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->post('/api/v1/admin/venues/999/images', ['images' => $this->pngs(1)], ['Accept' => 'application/json'])
            ->assertStatus(404);
    }

    public function test_photo_limit_is_rechecked_after_upload_and_extra_uploads_are_deleted(): void
    {
        $venue = $this->makeVenue();
        foreach (range(0, 3) as $i) {
            $venue->images()->create(['image_url' => "https://res.cloudinary.com/x/{$i}.jpg", 'sort_order' => $i]);
        }

        // A competing request adds two photos while these two are uploading (4 + 2 + 2 > 6).
        $this->duringUpload = function () use ($venue) {
            $this->duringUpload = null;
            $venue->images()->create(['image_url' => 'https://res.cloudinary.com/x/c1.jpg', 'sort_order' => 10]);
            $venue->images()->create(['image_url' => 'https://res.cloudinary.com/x/c2.jpg', 'sort_order' => 11]);
        };

        $this->post("/api/v1/admin/venues/{$venue->id}/images", ['images' => $this->pngs(2)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);

        $this->assertSame(6, $venue->images()->count());
        $this->assertSame(['playvo/venues/photo1', 'playvo/venues/photo2'], $this->destroyed);
    }

    public function test_inactive_venue_owner_cannot_be_chosen(): void
    {
        $owner = $this->userWithRole('venue_owner');
        $owner->update(['status' => 'locked']);

        $this->postJson('/api/v1/admin/venues', [
            'owner_id' => $owner->id,
            'name_ar' => 'ملعب',
            'city_id' => $this->city->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.owner_id.0', 'The selected venue owner account is not active.');

        $this->assertNothingCreated();
    }

    public function test_status_route_alias_works_like_the_old_path(): void
    {
        $venue = $this->makeVenue();

        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data', ['id' => $venue->id, 'status' => 'inactive']);

        $this->putJson("/api/v1/admin/admin/venues/{$venue->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->putJson("/api/v1/admin/venues/{$venue->id}/status", ['status' => 'removed'])
            ->assertStatus(422);
    }

    public function test_create_venue_owner_endpoint_survives_a_mail_failure(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

        $this->postJson('/api/v1/admin/venue-owners', [
            'name' => 'Owner Two',
            'email' => 'owner.two@example.com',
            'phone' => '0599000000',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'owner.two@example.com');

        $this->assertTrue(User::where('email', 'owner.two@example.com')->firstOrFail()->hasRole('venue_owner'));
    }

    // ---- Access ----

    public function test_non_admins_get_403_on_every_admin_venue_route(): void
    {
        $venue = $this->makeVenue();

        foreach (['player', 'venue_owner'] as $role) {
            $this->actingAsRole($role);

            $this->getJson('/api/v1/admin/venues')->assertStatus(403);
            $this->getJson("/api/v1/admin/venues/{$venue->id}")->assertStatus(403);
            $this->postJson('/api/v1/admin/venues', $this->newOwnerPayload())->assertStatus(403);
            $this->postJson('/api/v1/admin/admin/venues', $this->newOwnerPayload())->assertStatus(403);
            $this->postJson("/api/v1/admin/venues/{$venue->id}/images", [])->assertStatus(403);
            $this->putJson("/api/v1/admin/admin/venues/{$venue->id}/status", ['status' => 'inactive'])->assertStatus(403);
        }

        $this->assertSame(1, Venue::count());
    }
}
