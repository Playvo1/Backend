<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Sport;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Demo Football venues with free evening slots, so a fresh server has something to book and
 * the booking assistant has something to suggest. Run with
 * `php artisan db:seed --class=DemoDataSeeder`; on Render it runs on start when
 * SEED_DEMO_DATA=true.
 *
 * Safe to run on every deploy, and running it daily keeps 14 days of slots ahead:
 * - each demo venue has its own owner (one venue per owner), found by e-mail;
 * - a venue that already exists is not changed (an admin may have edited or deactivated
 *   it), and one that was removed is not brought back;
 * - a slot is only added when its venue has none for that sport, day and start, so
 *   booked, held (blocked) and edited slots are never touched;
 * - nothing outside the demo owners' venues is read or written.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Today (the hours not started yet) and the 13 days after it.
     */
    public const DAYS = 14;

    /**
     * Hourly slots from 16:00; the last one is 23:00-00:00 (00:00 is midnight, as elsewhere).
     */
    public const FIRST_HOUR = 16;

    public const LAST_HOUR = 23;

    /**
     * Seeded city names (CitySeeder) and the other spellings in use.
     */
    private const CITY_NAMES = [
        'Gaza' => ['Gaza'],
        'Khan Yunis' => ['Khan Yunis', 'Khan Younis', 'Khanyounis'],
        'Deir al-Balah' => ['Deir al-Balah', 'Deir Al-Balah'],
        'Nuseirat' => ['Nuseirat', 'Al-Nuseirat'],
    ];

    /**
     * The demo venues; base_price is the off-peak hourly price in ILS.
     */
    public const VENUES = [
        [
            'owner_email' => 'owner.rimal@playvo.test',
            'owner_name' => 'Rimal Court Owner',
            'city' => 'Gaza',
            'name_ar' => 'ملعب الرمال الخماسي',
            'name_en' => 'Al-Rimal Five-a-side Court',
            'area_ar' => 'الرمال',
            'area_en' => 'Al-Rimal',
            'address_ar' => 'شارع عمر المختار، حي الرمال، غزة',
            'address_en' => 'Omar Al-Mukhtar Street, Al-Rimal, Gaza',
            'latitude' => 31.5236,
            'longitude' => 34.4386,
            'length_m' => 40,
            'width_m' => 20,
            'avg_rating' => 4.6,
            'base_price' => 80,
        ],
        [
            'owner_email' => 'owner.sheikhradwan@playvo.test',
            'owner_name' => 'Sheikh Radwan Stadium Owner',
            'city' => 'Gaza',
            'name_ar' => 'ملعب الشيخ رضوان',
            'name_en' => 'Sheikh Radwan Stadium',
            'area_ar' => 'الشيخ رضوان',
            'area_en' => 'Sheikh Radwan',
            'address_ar' => 'قرب بركة الشيخ رضوان، غزة',
            'address_en' => 'Near the Sheikh Radwan pool, Gaza',
            'latitude' => 31.5375,
            'longitude' => 34.4700,
            'length_m' => 45,
            'width_m' => 25,
            'avg_rating' => 4.2,
            'base_price' => 70,
        ],
        [
            'owner_email' => 'owner.khanyunis@playvo.test',
            'owner_name' => 'Khan Yunis Court Owner',
            'city' => 'Khan Yunis',
            'name_ar' => 'ملعب خانيونس الرياضي',
            'name_en' => 'Khan Yunis Sports Court',
            'area_ar' => 'حي الأمل',
            'area_en' => 'Al-Amal',
            'address_ar' => 'شارع جمال عبد الناصر، حي الأمل، خانيونس',
            'address_en' => 'Gamal Abdel Nasser Street, Al-Amal, Khan Yunis',
            'latitude' => 31.3440,
            'longitude' => 34.3040,
            'length_m' => 40,
            'width_m' => 20,
            'avg_rating' => 4.8,
            'base_price' => 90,
        ],
        [
            'owner_email' => 'owner.deir@playvo.test',
            'owner_name' => 'Deir al-Balah Court Owner',
            'city' => 'Deir al-Balah',
            'name_ar' => 'ملعب البصة',
            'name_en' => 'Al-Bassa Court',
            'area_ar' => 'البصة',
            'area_en' => 'Al-Bassa',
            'address_ar' => 'حي البصة، دير البلح',
            'address_en' => 'Al-Bassa, Deir al-Balah',
            'latitude' => 31.4170,
            'longitude' => 34.3500,
            'length_m' => 38,
            'width_m' => 18,
            'avg_rating' => 3.8,
            'base_price' => 60,
        ],
        [
            'owner_email' => 'owner.nuseirat@playvo.test',
            'owner_name' => 'Nuseirat Arena Owner',
            'city' => 'Nuseirat',
            'name_ar' => 'ساحة النصيرات',
            'name_en' => 'Nuseirat Arena',
            'area_ar' => 'المخيم الجديد',
            'area_en' => 'New Camp',
            'address_ar' => 'شارع صلاح الدين، المخيم الجديد، النصيرات',
            'address_en' => 'Salah al-Din Road, New Camp, Nuseirat',
            'latitude' => 31.4480,
            'longitude' => 34.3930,
            'length_m' => 42,
            'width_m' => 22,
            'avg_rating' => 4.4,
            'base_price' => 75,
        ],
    ];

    public function run(): void
    {
        $football = Sport::query()->where('name_en', 'Football')->first();

        if ($football === null) {
            $this->warn('DemoDataSeeder: there is no Football sport (run SportSeeder first); no demo venue was linked or given slots.');
        }

        $password = (string) config('services.demo.owner_password');

        if ($password === '') {
            $this->warn('DemoDataSeeder: DEMO_OWNER_PASSWORD is empty, so new demo owners get a random password and can\'t log in.');
        }

        $role = Role::findOrCreate('venue_owner', 'web');
        $now = CarbonImmutable::now(config('app.local_timezone'));

        foreach (self::VENUES as $definition) {
            $city = $this->city($definition['city']);

            if ($city === null) {
                $this->warn("DemoDataSeeder: city {$definition['city']} is not seeded; skipped {$definition['name_en']}.");

                continue;
            }

            $owner = $this->owner($definition, $password, $role);
            $venue = $this->venue($definition, $owner, $city);

            if ($venue === null || $football === null) {
                continue;
            }

            DB::table('venue_sports')->insertOrIgnore(['venue_id' => $venue->id, 'sport_id' => $football->id]);
            $this->slots($venue, $football, $definition['base_price'], $now);
        }
    }

    private function city(string $name): ?City
    {
        return City::query()->whereIn('name_en', self::CITY_NAMES[$name] ?? [$name])->orderBy('id')->first();
    }

    /**
     * The demo owner, created active and verified. A set DEMO_OWNER_PASSWORD becomes the
     * password (also of an existing demo owner); without it an existing password is kept.
     */
    private function owner(array $definition, string $password, Role $role): User
    {
        $owner = User::query()->where('email', $definition['owner_email'])->first();

        if ($owner === null) {
            $owner = User::query()->create([
                'name' => $definition['owner_name'],
                'email' => $definition['owner_email'],
                'password' => $password !== '' ? $password : Str::random(48),
                'status' => 'active',
                'email_verified_at' => now(),
            ]);
        } elseif ($password !== '' && ! Hash::check($password, $owner->password)) {
            $owner->update(['password' => $password]);
        }

        if (! $owner->hasRole($role)) {
            $owner->assignRole($role);
        }

        return $owner;
    }

    /**
     * The owner's venue, created with the demo details the first time; null when an admin
     * removed it (it stays removed).
     */
    private function venue(array $definition, User $owner, City $city): ?Venue
    {
        $venue = Venue::withTrashed()->where('owner_id', $owner->id)->orderBy('id')->first();

        if ($venue?->trashed()) {
            return null;
        }

        return $venue ?? Venue::query()->create([
            'owner_id' => $owner->id,
            'city_id' => $city->id,
            'status' => 'active',
            'min_hourly_price' => $definition['base_price'],
            ...array_intersect_key($definition, array_flip([
                'name_ar', 'name_en', 'area_ar', 'area_en', 'address_ar', 'address_en',
                'latitude', 'longitude', 'length_m', 'width_m', 'avg_rating',
            ])),
        ]);
    }

    /**
     * Free hourly slots from FIRST_HOUR to LAST_HOUR for today (the hours not started yet, in
     * the players' local time) and the following days, for any day and start the venue has
     * no Football slot for yet. Prices are higher in the evening peak (19:00-22:00) and at
     * the weekend (Friday and Saturday).
     */
    private function slots(Venue $venue, Sport $sport, int $basePrice, CarbonImmutable $now): void
    {
        $today = $now->startOfDay();
        $existing = TimeSlot::query()
            ->where('venue_id', $venue->id)
            ->where('sport_id', $sport->id)
            ->whereDate('slot_date', '>=', $today->toDateString())
            ->get(['slot_date', 'start_time'])
            ->map(fn (TimeSlot $slot) => substr((string) $slot->slot_date, 0, 10).' '.substr((string) $slot->start_time, 0, 5))
            ->flip();
        $rows = [];

        for ($day = 0; $day < self::DAYS; $day++) {
            $date = $today->addDays($day);

            for ($hour = self::FIRST_HOUR; $hour <= self::LAST_HOUR; $hour++) {
                $start = sprintf('%02d:00', $hour);

                if (($day === 0 && $hour <= $now->hour) || isset($existing[$date->toDateString().' '.$start])) {
                    continue;
                }

                $peak = $hour >= 19 && $hour <= 21 ? 30 : 0;
                $weekend = in_array($date->dayOfWeek, [CarbonImmutable::FRIDAY, CarbonImmutable::SATURDAY], true) ? 15 : 0;

                $rows[] = [
                    'venue_id' => $venue->id,
                    'sport_id' => $sport->id,
                    'slot_date' => $date->toDateString(),
                    'start_time' => $start.':00',
                    'end_time' => $hour === 23 ? '00:00:00' : sprintf('%02d:00:00', $hour + 1),
                    'hourly_price' => $basePrice + $peak + $weekend,
                    'status' => 'available',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            TimeSlot::query()->insert($chunk);
        }
    }

    private function warn(string $message): void
    {
        $this->command?->warn($message);
    }
}
