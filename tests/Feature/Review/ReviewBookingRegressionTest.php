<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\Notification;
use App\Models\PaymentReceipt;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class ReviewBookingRegressionTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private User $player;

    private TimeSlot $slot;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->seedRoles();
        $this->player = $this->userWithRole('player');
        $this->slot = $this->makeSlot($this->makeVenue());
    }

    private function book(): Booking
    {
        Sanctum::actingAs($this->player);
        $this->postJson('/api/v1/bookings', [
            'time_slot_id' => $this->slot->id, 'captain_name' => 'Omar', 'captain_role' => 'Team Captain', 'captain_phone' => '0599123456',
        ])->assertCreated()->assertJsonPath('data.status', 'pending_payment');

        return Booking::firstOrFail();
    }

    public function test_booking_holds_the_slot_and_second_booking_conflicts(): void
    {
        $this->book();
        $this->assertNotSame('available', $this->slot->fresh()->status);

        Sanctum::actingAs($this->userWithRole('player'));
        $this->postJson('/api/v1/bookings', [
            'time_slot_id' => $this->slot->id, 'captain_name' => 'B', 'captain_role' => 'C', 'captain_phone' => '1',
        ])->assertStatus(409)->assertJson(['success' => false]);
    }

    public function test_receipt_upload_still_works_and_rejects_duplicates(): void
    {
        $booking = $this->book();
        $file = fn () => UploadedFile::fake()->createWithContent('r.png', base64_decode(self::PNG));

        $this->post("/api/v1/bookings/{$booking->id}/payment-receipt", ['receipt' => $file()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->post("/api/v1/bookings/{$booking->id}/payment-receipt", ['receipt' => $file()], ['Accept' => 'application/json'])
            ->assertStatus(409);
    }

    public function test_venue_search_still_lists_active_venues(): void
    {
        $this->getJson('/api/v1/venues?search=Green')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_expire_releases_slot_of_unpaid_booking(): void
    {
        $booking = $this->book();
        $this->travel(11)->minutes();

        $this->artisan('bookings:expire')->assertSuccessful();

        $this->assertSame('available', $this->slot->fresh()->status);
        $this->assertNotSame('pending_payment', Booking::find($booking->id)?->status);
    }

    public function test_expire_cancels_instead_of_deleting_the_booking(): void
    {
        // Contract 7.6: "the booking is automatically cancelled (status set to cancelled)".
        $booking = $this->book();
        $this->travel(11)->minutes();

        $this->artisan('bookings:expire')->assertSuccessful();

        $this->assertSame('cancelled', Booking::find($booking->id)?->status);
    }

    public function test_expire_skips_bookings_with_a_pending_receipt(): void
    {
        $booking = $this->book();
        PaymentReceipt::forceCreate(['booking_id' => $booking->id, 'image_url' => 'x', 'receipt_hash' => Str::random(64), 'status' => 'pending', 'uploaded_at' => now()]);
        $this->travel(30)->minutes();

        $this->artisan('bookings:expire')->assertSuccessful();

        $this->assertSame('pending_payment', $booking->fresh()->status);
    }

    public function test_rejected_receipt_gets_a_new_window_then_expires(): void
    {
        $booking = $this->book();
        $receipt = PaymentReceipt::forceCreate(['booking_id' => $booking->id, 'image_url' => 'x', 'receipt_hash' => Str::random(64), 'status' => 'pending', 'uploaded_at' => now()]);
        $this->travel(30)->minutes();

        Sanctum::actingAs($this->userWithRole('admin'));
        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'rejected', 'rejection_reason' => 'Wrong amount'])->assertOk();

        $this->artisan('bookings:expire')->assertSuccessful();
        $this->assertSame('pending_payment', $booking->fresh()->status, 'Re-upload window must protect the booking');

        $this->travel(11)->minutes();
        $this->artisan('bookings:expire')->assertSuccessful();
        $this->assertSame('available', $this->slot->fresh()->status);
    }

    public function test_expiry_after_rejection_keeps_the_rejected_receipt_history(): void
    {
        $booking = $this->book();
        $receipt = PaymentReceipt::forceCreate(['booking_id' => $booking->id, 'image_url' => 'x', 'receipt_hash' => Str::random(64), 'status' => 'pending', 'uploaded_at' => now()]);

        Sanctum::actingAs($this->userWithRole('admin'));
        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'rejected', 'rejection_reason' => 'Wrong amount'])->assertOk();
        $this->travel(11)->minutes();
        $this->artisan('bookings:expire')->assertSuccessful();

        // bookings:expire hard-deletes the booking; payment_receipts.booking_id cascades, so the
        // rejection (and its reason) disappears and the player's notification loses its booking link.
        $this->assertNotNull(PaymentReceipt::find($receipt->id));
        $this->assertNotNull(Notification::where('type', 'receipt_rejected')->value('booking_id'));
    }
}
