<?php

namespace Tests\Concerns;

use App\Models\City;
use App\Models\Country;
use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Small builders for the venue / slot graph used by the assistant feature tests,
 * so each test only states what it cares about.
 */
trait BuildsPlayvoData
{
    protected function seedRoles(): void
    {
        $this->seed(RoleSeeder::class);
    }

    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    protected function actingAsRole(string $role): User
    {
        $user = $this->userWithRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function makeCity(string $nameEn = 'Gaza', string $nameAr = 'غزة'): City
    {
        $country = Country::first() ?? Country::forceCreate(['name_ar' => 'فلسطين', 'name_en' => 'Palestine']);

        return City::forceCreate(['country_id' => $country->id, 'name_ar' => $nameAr, 'name_en' => $nameEn]);
    }

    protected function makeSport(string $nameEn = 'Football', string $nameAr = 'كرة القدم'): Sport
    {
        return Sport::forceCreate(['name_ar' => $nameAr, 'name_en' => $nameEn]);
    }

    protected function makeVenue(?User $owner = null, array $overrides = []): Venue
    {
        return Venue::forceCreate(array_merge([
            'owner_id' => ($owner ?? $this->userWithRole('venue_owner'))->id,
            'city_id' => $overrides['city_id'] ?? City::first()?->id ?? $this->makeCity()->id,
            'name_ar' => 'ملعب الحقل الأخضر',
            'name_en' => 'Green Field Court',
            'status' => 'active',
        ], $overrides));
    }

    /**
     * A slot, with the venue offering its sport through venue_sports.
     */
    protected function makeSlot(Venue $venue, array $overrides = []): TimeSlot
    {
        $sportId = $overrides['sport_id'] ?? Sport::first()?->id ?? $this->makeSport()->id;

        DB::table('venue_sports')->insertOrIgnore(['venue_id' => $venue->id, 'sport_id' => $sportId]);

        return TimeSlot::forceCreate(array_merge([
            'venue_id' => $venue->id,
            'sport_id' => $sportId,
            'slot_date' => now()->addDays(3)->toDateString(),
            'start_time' => '18:00:00',
            'end_time' => '19:00:00',
            'hourly_price' => 40,
            'status' => 'available',
        ], $overrides));
    }
}
