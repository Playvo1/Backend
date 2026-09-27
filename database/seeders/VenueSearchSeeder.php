<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Seeder;

class VenueSearchSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();

        if (!$user) {
            $this->command->error('No users found.');
            return;
        }

        // Country
        $country = Country::create([
            'name_ar' => 'فلسطين',
            'name_en' => 'Palestine',
        ]);

        // City
        $city = City::create([
            'country_id' => $country->id,
            'name_ar' => 'غزة',
            'name_en' => 'Gaza',
        ]);

        // Sport
        $sport = Sport::create([
            'name_ar' => 'كرة القدم',
            'name_en' => 'Football',
        ]);

        // Venue
        $venue = Venue::create([
            'owner_id' => $user->id,
            'city_id' => $city->id,
            'name_ar' => 'ملعب بلايفو',
            'name_en' => 'Playvo Stadium',
            'address_ar' => 'غزة',
            'address_en' => 'Gaza',
            'area_ar' => 'غزة',
            'area_en' => 'Gaza',
            'latitude' => 31.5000000,
            'longitude' => 34.4700000,
            'length_m' => 40,
            'width_m' => 20,
            'avg_rating' => 4.50,
            'min_hourly_price' => 100,
            'status' => 'active',
        ]);

        // Connect venue with sport
        $venue->sports()->attach($sport->id);

        // Available slot: 8 PM - 9 PM
        TimeSlot::create([
            'venue_id' => $venue->id,
            'sport_id' => $sport->id,
            'slot_date' => '2026-09-22',
            'start_time' => '20:00:00',
            'end_time' => '21:00:00',
            'hourly_price' => 100,
            'status' => 'available',
        ]);

        // Available slot: 9 PM - 10 PM
        TimeSlot::create([
            'venue_id' => $venue->id,
            'sport_id' => $sport->id,
            'slot_date' => '2026-09-22',
            'start_time' => '21:00:00',
            'end_time' => '22:00:00',
            'hourly_price' => 120,
            'status' => 'available',
        ]);

        // Booked slot: 10 PM - 11 PM
        TimeSlot::create([
            'venue_id' => $venue->id,
            'sport_id' => $sport->id,
            'slot_date' => '2026-09-22',
            'start_time' => '22:00:00',
            'end_time' => '23:00:00',
            'hourly_price' => 120,
            'status' => 'booked',
        ]);

        $this->command->info('Test data created successfully.');
        $this->command->info("Country ID: {$country->id}");
        $this->command->info("City ID: {$city->id}");
        $this->command->info("Sport ID: {$sport->id}");
        $this->command->info("Venue ID: {$venue->id}");
    }
}
