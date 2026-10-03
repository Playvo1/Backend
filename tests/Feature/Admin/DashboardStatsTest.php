<?php

namespace Tests\Feature\Admin;

use App\Models\PaymentReceipt;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 12:00:00');
        $this->seedRoles();
    }

    /**
     * Creates $count bookings at the venue, each on its own slot, created at $createdAt.
     */
    private function bookingsAt(Venue $venue, int $count, string $createdAt = '2026-10-15 09:00:00', string $status = 'confirmed'): void
    {
        foreach (range(1, $count) as $i) {
            $slot = $this->makeSlot($venue, ['slot_date' => now()->addDays(5 + $venue->timeSlots()->count())->toDateString()]);
            $this->makeBooking($slot, ['status' => $status, 'created_at' => $createdAt]);
        }
    }

    public function test_admin_sees_platform_wide_statistics(): void
    {
        $gaza = $this->makeCity();
        $khanYounis = $this->makeCity('Khan Younis', 'خانيونس');
        $greenField = $this->makeVenue(null, ['city_id' => $gaza->id, 'name_en' => 'Green Field Court']);
        $rimal = $this->makeVenue(null, ['city_id' => $gaza->id, 'name_en' => 'Rimal Arena']);
        $stadium = $this->makeVenue(null, ['city_id' => $khanYounis->id, 'name_en' => 'KY Stadium', 'status' => 'inactive']);

        $this->bookingsAt($greenField, 3);
        $this->bookingsAt($rimal, 1, '2026-10-02 09:00:00');
        $this->bookingsAt($stadium, 2, '2026-09-20 09:00:00');
        $this->bookingsAt($stadium, 1, status: 'cancelled');

        $booking = $greenField->timeSlots()->first()->booking;
        PaymentReceipt::forceCreate(['booking_id' => $booking->id, 'image_url' => 'x.png', 'receipt_hash' => Str::random(64), 'status' => 'pending']);

        $this->actingAsRole('admin');

        $this->getJson('/api/v1/admin/dashboard-stats')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'OK',
                'errors' => null,
                'data' => [
                    'total_users' => User::count(),
                    'total_venues' => 3,
                    'active_venues' => 2,
                    'bookings_today' => 3,
                    'bookings_this_month' => 4,
                    'pending_receipts' => 1,
                    'most_booked_venues' => [
                        ['venue_id' => $greenField->id, 'name_ar' => $greenField->name_ar, 'name_en' => 'Green Field Court', 'bookings_count' => 3],
                        ['venue_id' => $stadium->id, 'name_ar' => $stadium->name_ar, 'name_en' => 'KY Stadium', 'bookings_count' => 2],
                        ['venue_id' => $rimal->id, 'name_ar' => $rimal->name_ar, 'name_en' => 'Rimal Arena', 'bookings_count' => 1],
                    ],
                    'most_active_cities' => [
                        ['city_id' => $gaza->id, 'name_ar' => 'غزة', 'name_en' => 'Gaza', 'bookings_count' => 4],
                        ['city_id' => $khanYounis->id, 'name_ar' => 'خانيونس', 'name_en' => 'Khan Younis', 'bookings_count' => 2],
                    ],
                ],
            ]);
    }

    public function test_empty_platform_returns_zeros(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/v1/admin/dashboard-stats')
            ->assertOk()
            ->assertJson(['data' => [
                'total_users' => 1,
                'total_venues' => 0,
                'bookings_today' => 0,
                'pending_receipts' => 0,
                'most_booked_venues' => [],
                'most_active_cities' => [],
            ]]);
    }

    public function test_only_the_top_five_venues_are_listed(): void
    {
        foreach (range(1, 6) as $i) {
            $this->bookingsAt($this->makeVenue(), 1);
        }
        $this->actingAsRole('admin');

        $this->getJson('/api/v1/admin/dashboard-stats')->assertOk()->assertJsonCount(5, 'data.most_booked_venues');
    }

    public function test_non_admins_cannot_see_dashboard_stats(): void
    {
        $this->actingAsRole('venue_owner');

        $this->getJson('/api/v1/admin/dashboard-stats')
            ->assertForbidden()
            ->assertJson(['success' => false, 'data' => null]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/dashboard-stats')->assertUnauthorized();
    }
}
