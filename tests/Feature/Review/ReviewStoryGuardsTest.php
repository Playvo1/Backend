<?php

namespace Tests\Feature\Review;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\PaymentReceipt;
use App\Models\Sport;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class ReviewStoryGuardsTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    private User $owner;

    private Venue $venue;

    private Sport $sport;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedRoles();
        $this->travelTo('2026-10-07 10:00:00'); // a Wednesday
        $this->sport = $this->makeSport();
        $this->owner = $this->userWithRole('venue_owner');
        $this->venue = $this->makeVenue($this->owner, [
            'address_ar' => 'a', 'address_en' => 'a', 'area_ar' => 'الجلاء', 'area_en' => 'Al-Jalaa',
            'latitude' => 31.5, 'longitude' => 34.4, 'length_m' => 40, 'width_m' => 20,
        ]);
        $this->venue->sports()->sync([$this->sport->id]);
    }

    // ---- US-3.3 slots ----

    public function test_editing_or_deleting_a_held_slot_is_409(): void
    {
        $slot = $this->makeSlot($this->venue);
        $this->makeBooking($slot, ['status' => 'pending_payment']);
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/owner/time-slots/{$slot->id}", ['hourly_price' => 1])
            ->assertStatus(409)->assertJson(['success' => false, 'message' => 'This slot already has a booking and cannot be changed.']);
        $this->deleteJson("/api/v1/owner/time-slots/{$slot->id}")->assertStatus(409);
    }

    public function test_editing_or_deleting_a_booked_slot_is_409(): void
    {
        $slot = $this->makeSlot($this->venue);
        $this->makeBooking($slot, ['status' => 'confirmed']);
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/owner/time-slots/{$slot->id}", ['start_time' => '20:00', 'end_time' => '21:00'])->assertStatus(409);
        $this->deleteJson("/api/v1/owner/time-slots/{$slot->id}")->assertStatus(409);
        $this->assertSame('18:00:00', $slot->fresh()->start_time);
    }

    public function test_conflict_wins_over_validation_on_a_booked_slot(): void
    {
        // The guard runs in the controller, after validation, so a bad payload on a booked slot
        // reports 422 instead of the story's "already has a booking" message.
        $slot = $this->makeSlot($this->venue);
        $this->makeBooking($slot, ['status' => 'confirmed']);
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/owner/time-slots/{$slot->id}", ['hourly_price' => -5])->assertStatus(409);
    }

    public function test_overlapping_slot_create_and_update_are_422(): void
    {
        $date = now()->addDays(3)->toDateString();
        $this->makeSlot($this->venue, ['slot_date' => $date]); // 18-19
        $other = $this->makeSlot($this->venue, ['slot_date' => $date, 'start_time' => '20:00:00', 'end_time' => '21:00:00']);
        Sanctum::actingAs($this->owner);

        $payload = ['sport_id' => $this->sport->id, 'slot_date' => $date, 'hourly_price' => 40];
        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", [...$payload, 'start_time' => '18:30', 'end_time' => '19:30'])
            ->assertStatus(422)->assertJsonValidationErrors('start_time');
        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", [...$payload, 'start_time' => '17:00', 'end_time' => '22:00'])
            ->assertStatus(422);
        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", [...$payload, 'start_time' => '19:00', 'end_time' => '20:00'])
            ->assertCreated(); // touching edges are fine
        $this->putJson("/api/v1/owner/time-slots/{$other->id}", ['start_time' => '18:30'])->assertStatus(422);
        $this->putJson("/api/v1/owner/time-slots/{$other->id}", ['end_time' => '19:00'])->assertStatus(422)->assertJsonValidationErrors('end_time');
    }

    public function test_create_slot_contract_response(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", [
            'sport_id' => $this->sport->id, 'slot_date' => '2026-10-10', 'start_time' => '18:00', 'end_time' => '19:00', 'hourly_price' => 40,
        ])->assertCreated()->assertJson(['success' => true, 'message' => 'Time slot created', 'data' => ['status' => 'available', 'hourly_price' => 40]]);
    }

    public function test_cannot_create_a_slot_that_already_started_today(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/owner/venues/{$this->venue->id}/time-slots", [
            'sport_id' => $this->sport->id, 'slot_date' => '2026-10-07', 'start_time' => '06:00', 'end_time' => '07:00', 'hourly_price' => 40,
        ])->assertStatus(422);
    }

    // ---- US-3.5 venues ----

    public function test_removal_is_blocked_by_upcoming_pending_or_confirmed_bookings(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $slot = $this->makeSlot($this->venue);
        $this->makeBooking($slot, ['status' => 'pending_payment']);

        $this->putJson("/api/v1/admin/venues/{$this->venue->id}/status", ['status' => 'inactive'])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => "Resolve this venue's upcoming bookings before removing it."]);
        $this->assertNotSoftDeleted($this->venue);
    }

    public function test_past_and_cancelled_bookings_do_not_block_removal(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $this->makeBooking($this->makeSlot($this->venue, ['slot_date' => '2026-10-01']), ['status' => 'confirmed']);
        $this->makeBooking($this->makeSlot($this->venue, ['slot_date' => '2026-10-20']), ['status' => 'cancelled']);
        $this->makeBooking($this->makeSlot($this->venue, ['slot_date' => '2026-10-07', 'start_time' => '08:00:00', 'end_time' => '09:00:00']), ['status' => 'confirmed']);

        $this->putJson("/api/v1/admin/venues/{$this->venue->id}/status", ['status' => 'inactive'])->assertOk();
        $this->assertSoftDeleted($this->venue);
        $this->assertSame(3, Booking::count());
    }

    public function test_removed_venue_disappears_from_player_endpoints(): void
    {
        $slot = $this->makeSlot($this->venue, ['slot_date' => '2026-10-08']);
        Sanctum::actingAs($this->userWithRole('admin'));
        $this->putJson("/api/v1/admin/venues/{$this->venue->id}/status", ['status' => 'inactive'])->assertOk();

        $this->getJson('/api/v1/venues')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson("/api/v1/venues/{$this->venue->id}")->assertNotFound();
        $this->getJson("/api/v1/venues/{$this->venue->id}/time-slots?date=2026-10-08&sport_id={$this->sport->id}")->assertNotFound();

        // Still listed for the admin, flagged as removed.
        $this->getJson('/api/v1/admin/venues')->assertOk()->assertJsonPath('data.0.id', $this->venue->id)
            ->assertJsonPath('data.0.status', 'inactive');
        $this->assertNotNull(Venue::withTrashed()->find($this->venue->id)->deleted_at);

        // Assistant (fallback parser) must not suggest it.
        config(['services.gemini.key' => null]);
        Sanctum::actingAs($this->userWithRole('player'));
        $this->postJson('/api/v1/assistant/query', ['query_text' => 'football tomorrow at 6pm'])
            ->assertOk()->assertJsonPath('data.suggested_venue_id', null);

        // Removing a venue blocks its future free slots so they can't be booked directly.
        $this->assertSame('blocked', $slot->fresh()->status);
    }

    public function test_slot_of_a_removed_venue_cannot_be_booked_directly(): void
    {
        $slot = $this->makeSlot($this->venue, ['slot_date' => '2026-10-08']);
        $this->venue->update(['status' => 'inactive']);
        $this->venue->delete();

        Sanctum::actingAs($this->userWithRole('player'));
        $response = $this->postJson('/api/v1/bookings', [
            'time_slot_id' => $slot->id, 'captain_name' => 'Omar', 'captain_role' => 'Captain', 'captain_phone' => '0599',
        ]);

        $this->assertContains($response->status(), [404, 409, 422], 'A removed venue\'s slot was bookable: '.$response->getContent());
    }

    public function test_approve_restores_a_removed_venue(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $this->venue->delete();

        $this->putJson("/api/v1/admin/venues/{$this->venue->id}/status", ['status' => 'active'])
            ->assertOk()->assertExactJson(['success' => true, 'message' => 'Venue approved', 'errors' => null, 'data' => ['id' => $this->venue->id, 'status' => 'active']]);
        $this->assertNotSoftDeleted($this->venue);
    }

    public function test_removing_an_already_removed_venue_is_not_logged_twice(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $this->putJson("/api/v1/admin/venues/{$this->venue->id}/status", ['status' => 'inactive'])->assertOk();
        $this->putJson("/api/v1/admin/venues/{$this->venue->id}/status", ['status' => 'inactive']);

        $this->assertSame(1, AuditLog::where('target_id', $this->venue->id)->count());
    }

    // ---- US-3.6 receipts ----

    private function pendingReceipt(): PaymentReceipt
    {
        $hour = 6 + PaymentReceipt::count();
        $slot = $this->makeSlot($this->venue, ['start_time' => sprintf('%02d:00:00', $hour), 'end_time' => sprintf('%02d:59:00', $hour)]);
        $booking = $this->makeBooking($slot, ['status' => 'pending_payment', 'hold_expires_at' => now()->subMinute()]);

        return PaymentReceipt::forceCreate([
            'booking_id' => $booking->id, 'image_url' => 'payment-receipts/x.png', 'receipt_hash' => Str::random(64),
            'status' => 'pending', 'uploaded_at' => now(),
        ]);
    }

    public function test_verify_confirms_booking_and_books_slot(): void
    {
        $admin = $this->actingAsRole('admin');
        $receipt = $this->pendingReceipt();

        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'verified'])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Receipt verified, booking confirmed', 'data' => ['id' => $receipt->id, 'status' => 'verified', 'verified_by' => $admin->id]]);

        $booking = $receipt->booking->fresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('booked', $booking->timeSlot->status);
        $this->assertSame(1, Notification::where('type', 'booking_confirmed')->count());
    }

    public function test_reject_requires_reason_and_reopens_payment_window(): void
    {
        $this->actingAsRole('admin');
        $receipt = $this->pendingReceipt();

        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'rejected'])
            ->assertStatus(422)->assertJsonValidationErrors('rejection_reason');
        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'rejected', 'rejection_reason' => '   '])
            ->assertStatus(422);

        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'rejected', 'rejection_reason' => 'Blurry'])
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Receipt rejected, player notified', 'errors' => null, 'data' => ['id' => $receipt->id, 'status' => 'rejected', 'rejection_reason' => 'Blurry']]);

        $booking = $receipt->booking->fresh();
        $this->assertSame('pending_payment', $booking->status);
        $this->assertTrue($booking->hold_expires_at->isFuture());
        $this->assertStringContainsString('Blurry', Notification::where('type', 'receipt_rejected')->value('body'));
    }

    public function test_double_verify_and_verify_after_reject_are_409(): void
    {
        $this->actingAsRole('admin');
        $a = $this->pendingReceipt();
        $b = $this->pendingReceipt();

        $this->putJson("/api/v1/admin/payment-receipts/{$a->id}/verify", ['status' => 'verified'])->assertOk();
        $this->putJson("/api/v1/admin/payment-receipts/{$a->id}/verify", ['status' => 'verified'])
            ->assertStatus(409)->assertJson(['success' => false, 'data' => null]);
        $this->putJson("/api/v1/admin/payment-receipts/{$a->id}/verify", ['status' => 'rejected', 'rejection_reason' => 'x'])->assertStatus(409);

        $this->putJson("/api/v1/admin/payment-receipts/{$b->id}/verify", ['status' => 'rejected', 'rejection_reason' => 'x'])->assertOk();
        $this->putJson("/api/v1/admin/payment-receipts/{$b->id}/verify", ['status' => 'verified'])->assertStatus(409);
        $this->assertSame('pending_payment', $b->booking->fresh()->status);
        $this->assertSame(1, Notification::where('type', 'booking_confirmed')->count());
    }

    public function test_verify_on_a_cancelled_booking_is_409(): void
    {
        $this->actingAsRole('admin');
        $receipt = $this->pendingReceipt();
        $receipt->booking->update(['status' => 'cancelled']);

        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'verified'])->assertStatus(409);
    }

    // ---- US-3.4 weekly stats ----

    public function test_weekly_stats_week_boundaries(): void
    {
        Sanctum::actingAs($this->owner);
        $make = function (string $createdAt, string $status = 'confirmed', int $price = 40) {
            $slot = $this->makeSlot($this->venue, ['slot_date' => '2026-10-20', 'start_time' => sprintf('%02d:00:00', Booking::count()), 'end_time' => sprintf('%02d:59:00', Booking::count())]);
            $b = $this->makeBooking($slot, ['status' => $status, 'total_price' => $price]);
            // created_at is stored in UTC; the week is the local (Asia/Gaza) Monday-Sunday.
            $b->forceFill(['created_at' => Carbon::parse($createdAt, 'Asia/Gaza')->utc()])->save();
        };

        $make('2026-10-04 23:59:59');            // previous Sunday — excluded
        $make('2026-10-05 00:00:00');            // Monday start — included
        $make('2026-10-11 23:59:59', 'confirmed', 60); // Sunday end — included
        $make('2026-10-12 00:00:00');            // next Monday — excluded
        $make('2026-10-06 12:00:00', 'pending_payment');
        $make('2026-10-06 13:00:00', 'cancelled');

        $this->getJson('/api/v1/owner/bookings')
            ->assertOk()
            ->assertJsonPath('data.weekly_stats.week_start', '2026-10-05')
            ->assertJsonPath('data.weekly_stats.week_end', '2026-10-11')
            ->assertJsonPath('data.weekly_stats.total_bookings', 3)
            ->assertJsonPath('data.weekly_stats.confirmed_bookings', 2)
            ->assertJsonPath('data.weekly_stats.income', 100);
    }

    public function test_weekly_stats_week_follows_local_gaza_time(): void
    {
        // 22:30 UTC Sunday is 01:30 Monday in Gaza; the app timezone is UTC, so the
        // owner sees last week's numbers for the first hours of their Monday.
        $this->travelTo('2026-10-11 22:30:00');
        Sanctum::actingAs($this->owner);
        $this->makeBooking($this->makeSlot($this->venue, ['slot_date' => '2026-10-15']), ['status' => 'confirmed']);

        $this->getJson('/api/v1/owner/bookings')
            ->assertOk()
            ->assertJsonPath('data.weekly_stats.week_start', '2026-10-12')
            ->assertJsonPath('data.weekly_stats.total_bookings', 1);
    }

    public function test_owner_bookings_contract_shape_and_status_filter(): void
    {
        Sanctum::actingAs($this->owner);
        $this->makeBooking($this->makeSlot($this->venue), ['status' => 'confirmed']);
        $this->makeBooking($this->makeSlot($this->venue, ['start_time' => '20:00:00', 'end_time' => '21:00:00']), ['status' => 'cancelled']);

        $this->getJson("/api/v1/owner/bookings?venue_id={$this->venue->id}&status=confirmed")
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'OK', 'errors' => null, 'data' => ['total' => 1, 'page' => 1, 'per_page' => 20]])
            ->assertJsonStructure(['data' => ['items' => [['id', 'captain_name', 'status', 'total_price']]]]);
        $this->getJson('/api/v1/owner/bookings?status=pending')->assertStatus(422);
    }

    // ---- US-5.1 dashboard ----

    public function test_dashboard_excludes_cancelled_and_matches_contract_keys(): void
    {
        $this->actingAsRole('admin');
        $this->makeBooking($this->makeSlot($this->venue), ['status' => 'confirmed']);
        $this->makeBooking($this->makeSlot($this->venue, ['start_time' => '20:00:00', 'end_time' => '21:00:00']), ['status' => 'cancelled']);
        $this->makeBooking($this->makeSlot($this->venue, ['start_time' => '21:00:00', 'end_time' => '22:00:00']), ['status' => 'pending_payment']);

        $this->getJson('/api/v1/admin/dashboard-stats')
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'OK', 'errors' => null])
            ->assertJsonPath('data.bookings_today', 2)
            ->assertJsonPath('data.bookings_this_month', 2)
            ->assertJsonPath('data.most_booked_venues.0.bookings_count', 2)
            ->assertJsonPath('data.most_active_cities.0.bookings_count', 2)
            ->assertJsonStructure(['data' => ['total_venues', 'active_venues', 'bookings_today', 'pending_receipts', 'total_users', 'bookings_this_month']]);
    }
}
