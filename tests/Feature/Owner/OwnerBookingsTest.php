<?php

namespace Tests\Feature\Owner;

use App\Models\TimeSlot;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class OwnerBookingsTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    private User $owner;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday, so "this week" is Mon 2026-09-28 .. Sun 2026-10-04.
        $this->travelTo('2026-09-30 12:00:00');

        $this->seedRoles();
        $this->owner = $this->actingAsRole('venue_owner');
        $this->venue = $this->makeVenue($this->owner);
    }

    private function booking(string $status, float $price, string $createdAt = '2026-09-30 10:00:00', ?Venue $venue = null): int
    {
        $slot = $this->makeSlot($venue ?? $this->venue, [
            'start_time' => sprintf('%02d:00:00', 8 + TimeSlot::count()),
            'end_time' => sprintf('%02d:00:00', 9 + TimeSlot::count()),
            'hourly_price' => $price,
        ]);

        return $this->makeBooking($slot, ['status' => $status, 'created_at' => $createdAt])->id;
    }

    public function test_owner_sees_their_bookings_with_this_weeks_stats(): void
    {
        $confirmed = $this->booking('confirmed', 40);
        $this->booking('confirmed', 60);
        $this->booking('pending_payment', 50);
        $this->booking('cancelled', 70);
        $this->booking('confirmed', 100, '2026-09-20 10:00:00');

        $response = $this->getJson('/api/v1/owner/bookings');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'errors' => null,
                'data' => [
                    'total' => 5,
                    'page' => 1,
                    'per_page' => 20,
                    'weekly_stats' => [
                        'week_start' => '2026-09-28',
                        'week_end' => '2026-10-04',
                        'total_bookings' => 3,
                        'confirmed_bookings' => 2,
                        'income' => 100,
                    ],
                ],
            ])
            ->assertJsonStructure(['data' => ['items' => [[
                'id', 'venue_id', 'captain_name', 'status', 'total_price', 'slot_date', 'start_time', 'end_time',
            ]]]]);

        $this->assertContains($confirmed, array_column($response->json('data.items'), 'id'));
    }

    public function test_bookings_can_be_filtered_by_status(): void
    {
        $this->booking('confirmed', 40);
        $pending = $this->booking('pending_payment', 50);
        $this->booking('cancelled', 70);

        $this->getJson('/api/v1/owner/bookings?status=pending_payment')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $pending)
            ->assertJsonPath('data.items.0.status', 'pending_payment');
    }

    public function test_bookings_can_be_filtered_by_venue(): void
    {
        $second = $this->makeVenue($this->owner, ['name_en' => 'Second Court']);
        $this->booking('confirmed', 40);
        $onSecond = $this->booking('confirmed', 80, venue: $second);

        $this->getJson("/api/v1/owner/bookings?venue_id={$second->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $onSecond)
            ->assertJsonPath('data.weekly_stats.income', 80);
    }

    public function test_other_owners_bookings_are_never_listed(): void
    {
        $this->booking('confirmed', 40, venue: $this->makeVenue());

        $this->getJson('/api/v1/owner/bookings')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.weekly_stats.total_bookings', 0)
            ->assertJsonPath('data.weekly_stats.income', 0);
    }

    public function test_filtering_by_another_owners_venue_is_forbidden(): void
    {
        $other = $this->makeVenue();

        $this->getJson("/api/v1/owner/bookings?venue_id={$other->id}")
            ->assertForbidden()
            ->assertJson(['success' => false, 'data' => null]);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->getJson('/api/v1/owner/bookings?status=paid&venue_id=999&per_page=500')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'venue_id', 'per_page']);
    }

    public function test_pagination_follows_the_contract(): void
    {
        foreach (range(1, 3) as $i) {
            $this->booking('confirmed', 10);
        }

        $this->getJson('/api/v1/owner/bookings?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.page', 2)
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_players_cannot_see_owner_bookings(): void
    {
        $this->actingAsRole('player');

        $this->getJson('/api/v1/owner/bookings')->assertForbidden();
    }
}
