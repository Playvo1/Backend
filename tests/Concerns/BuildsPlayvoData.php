<?php

namespace Tests\Concerns;

use App\Models\Booking;
use App\Models\City;
use App\Models\Country;
use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Small builders for the venue / slot / booking graph shared by the owner,
 * admin and assistant feature tests, so each test only states what it cares about.
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

    protected function makeSlot(Venue $venue, array $overrides = []): TimeSlot
    {
        return TimeSlot::forceCreate(array_merge([
            'venue_id' => $venue->id,
            'sport_id' => $overrides['sport_id'] ?? Sport::first()?->id ?? $this->makeSport()->id,
            'slot_date' => now()->addDays(3)->toDateString(),
            'start_time' => '18:00:00',
            'end_time' => '19:00:00',
            'hourly_price' => 40,
            'status' => 'available',
        ], $overrides));
    }

    protected function makeBooking(TimeSlot $slot, array $overrides = []): Booking
    {
        $status = $overrides['status'] ?? 'confirmed';

        if ($status !== 'cancelled') {
            $slot->update(['status' => $status === 'confirmed' ? 'booked' : 'blocked']);
        }

        return Booking::forceCreate(array_merge([
            'time_slot_id' => $slot->id,
            'captain_user_id' => $overrides['captain_user_id'] ?? $this->userWithRole('player')->id,
            'captain_name' => 'Omar Yousef',
            'captain_role' => 'Team Captain',
            'captain_phone' => '0599123456',
            'total_price' => $slot->hourly_price,
            'status' => $status,
            'share_token' => Str::random(40),
        ], $overrides));
    }
}
