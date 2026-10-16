<?php

namespace Tests\Feature\Seeders;

use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\CitySeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EMAILS = [
        'owner.rimal@playvo.test', 'owner.sheikhradwan@playvo.test', 'owner.khanyunis@playvo.test',
        'owner.deir@playvo.test', 'owner.nuseirat@playvo.test',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // 15:30 UTC is 18:30 in Gaza (UTC+3 in October): today's 16:00-18:00 slots have started.
        $this->travelTo('2026-10-16 15:30:00');
        config(['app.local_timezone' => 'Asia/Gaza', 'services.demo.owner_password' => 'demo-secret-123', 'services.gemini.key' => null]);
        $this->seed([RoleSeeder::class, CitySeeder::class, SportSeeder::class]);
    }

    private function demoVenues()
    {
        return Venue::query()->whereIn('owner_id', User::query()->whereIn('email', self::EMAILS)->select('id'))->get();
    }

    public function test_five_active_football_venues_each_with_its_own_owner(): void
    {
        $this->seed(DemoDataSeeder::class);

        $venues = $this->demoVenues();
        $football = Sport::where('name_en', 'Football')->value('id');

        $this->assertCount(5, $venues);
        $this->assertCount(5, $venues->pluck('owner_id')->unique());
        $this->assertSame(['Deir al-Balah', 'Gaza', 'Khan Yunis', 'Nuseirat'], $venues->map(fn (Venue $venue) => $venue->city->name_en)->unique()->sort()->values()->all());

        foreach ($venues as $venue) {
            $this->assertSame('active', $venue->status);
            $this->assertTrue(DB::table('venue_sports')->where(['venue_id' => $venue->id, 'sport_id' => $football])->exists());
            $this->assertNotNull($venue->latitude);
            $this->assertTrue($venue->owner->hasRole('venue_owner'));
            $this->assertSame('active', $venue->owner->status);
            $this->assertNotNull($venue->owner->email_verified_at);
            $this->assertTrue(Hash::check('demo-secret-123', $venue->owner->password));
        }
    }

    public function test_slots_are_free_hourly_evenings_from_now_for_fourteen_days(): void
    {
        $this->seed(DemoDataSeeder::class);

        $venue = $this->demoVenues()->first();
        $slots = TimeSlot::where('venue_id', $venue->id)->orderBy('slot_date')->orderBy('start_time')->get();

        // Today 19:00-23:00 (16:00-18:00 already started), then 8 a day for 13 more days.
        $this->assertCount(5 + 13 * 8, $slots);
        $this->assertSame('2026-10-16', substr((string) $slots->first()->slot_date, 0, 10));
        $this->assertSame('19:00:00', substr((string) $slots->first()->start_time, 0, 8));
        $this->assertSame('2026-10-29', substr((string) $slots->last()->slot_date, 0, 10));
        $this->assertSame(['23:00:00', '00:00:00'], [substr((string) $slots->last()->start_time, 0, 8), substr((string) $slots->last()->end_time, 0, 8)]);
        $this->assertSame(['available'], $slots->pluck('status')->unique()->values()->all());
        $this->assertGreaterThanOrEqual(60, $slots->min('hourly_price'));
        $this->assertLessThanOrEqual(150, $slots->max('hourly_price'));
    }

    public function test_running_it_again_adds_nothing_and_keeps_booked_and_held_slots(): void
    {
        $this->seed(DemoDataSeeder::class);
        $venue = $this->demoVenues()->first();
        $booked = TimeSlot::where('venue_id', $venue->id)->orderBy('id')->first();
        $booked->update(['status' => 'booked', 'hourly_price' => 999]);
        $held = TimeSlot::where('venue_id', $venue->id)->orderBy('id')->skip(1)->first();
        $held->update(['status' => 'blocked']);
        $venue->update(['name_en' => 'Renamed by the owner', 'status' => 'inactive']);
        $counts = [User::count(), Venue::count(), TimeSlot::count(), DB::table('venue_sports')->count()];

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($counts, [User::count(), Venue::count(), TimeSlot::count(), DB::table('venue_sports')->count()]);
        $this->assertSame(['booked', 999.0], [$booked->fresh()->status, (float) $booked->fresh()->hourly_price]);
        $this->assertSame('blocked', $held->fresh()->status);
        $this->assertSame(['Renamed by the owner', 'inactive'], [$venue->fresh()->name_en, $venue->fresh()->status]);

        // A day later it tops the window up to 14 days again, without duplicates.
        $this->travel(1)->day();
        $this->seed(DemoDataSeeder::class);
        $this->assertSame(1, TimeSlot::where('venue_id', $venue->id)->whereDate('slot_date', '2026-10-30')->where('start_time', '20:00:00')->count());
        $this->assertSame(0, TimeSlot::query()->select('venue_id', 'sport_id', 'slot_date', 'start_time')
            ->groupBy('venue_id', 'sport_id', 'slot_date', 'start_time')->havingRaw('count(*) > 1')->get()->count());
    }

    public function test_a_removed_demo_venue_is_not_brought_back(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->demoVenues()->first()->delete();

        $this->seed(DemoDataSeeder::class);

        $this->assertCount(4, $this->demoVenues());
        $this->assertSame(5, Venue::withTrashed()->count());
    }

    public function test_other_venues_and_owners_are_never_touched(): void
    {
        $owner = User::factory()->create(['email' => 'real.owner@example.com']);
        $owner->assignRole('venue_owner');
        $real = Venue::forceCreate([
            'owner_id' => $owner->id, 'city_id' => DB::table('cities')->value('id'), 'name_ar' => 'ملعب حقيقي', 'name_en' => 'Real Court', 'status' => 'active',
        ]);
        $passwordHash = $owner->password;

        $this->seed(DemoDataSeeder::class);

        $this->assertSame(0, TimeSlot::where('venue_id', $real->id)->count());
        $this->assertFalse(DB::table('venue_sports')->where('venue_id', $real->id)->exists());
        $this->assertSame(['Real Court', 'active', $passwordHash], [$real->fresh()->name_en, $real->fresh()->status, $owner->fresh()->password]);
    }

    public function test_without_football_nothing_is_linked_and_nothing_breaks(): void
    {
        Sport::query()->delete();

        $this->seed(DemoDataSeeder::class);

        $this->assertSame(0, DB::table('venue_sports')->count());
        $this->assertSame(0, TimeSlot::count());
        $this->assertSame(0, Sport::count());
    }

    public function test_an_empty_password_gives_an_unusable_random_one(): void
    {
        config(['services.demo.owner_password' => '']);

        $this->seed(DemoDataSeeder::class);

        foreach (User::whereIn('email', self::EMAILS)->get() as $owner) {
            $this->assertNotEmpty($owner->password);
            $this->assertFalse(Hash::check('', $owner->password));
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'owner.rimal@playvo.test', 'password' => ''])->assertStatus(422);

        // A password set later becomes the demo owners' password on the next run.
        config(['services.demo.owner_password' => 'set-later-456']);
        $this->seed(DemoDataSeeder::class);
        $this->assertTrue(Hash::check('set-later-456', User::where('email', 'owner.rimal@playvo.test')->value('password')));
    }

    public function test_the_assistant_suggests_a_demo_venue(): void
    {
        Http::preventStrayRequests();
        $this->seed(DemoDataSeeder::class);
        $player = User::factory()->create(['status' => 'active']);
        $player->assignRole('player');
        Sanctum::actingAs($player);

        $response = $this->postJson('/api/v1/assistant/query', ['query_text' => 'بدي ملعب كرة قدم بكرا الساعة 8 بالليل'])->assertOk();

        $this->assertSame('2026-10-17', $response->json('data.parsed_date'));
        $this->assertSame('20:00', $response->json('data.parsed_hour'));
        $this->assertContains($response->json('data.suggested_venue_id'), $this->demoVenues()->modelKeys());
    }
}
