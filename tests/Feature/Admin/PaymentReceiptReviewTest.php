<?php

namespace Tests\Feature\Admin;

use App\Http\Resources\PaymentReceiptResource;
use App\Models\Booking;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\Payments\ReceiptReuseCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class PaymentReceiptReviewTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    // A valid 1x1 PNG, so the tests don't depend on the GD extension.
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->seedRoles();
        $this->admin = $this->actingAsRole('admin');
    }

    private function pendingBooking(array $slot = []): Booking
    {
        return $this->makeBooking($this->makeSlot($this->makeVenue(), $slot), ['status' => 'pending_payment']);
    }

    private function receipt(?Booking $booking = null, array $overrides = []): PaymentReceipt
    {
        $booking ??= $this->pendingBooking();
        $path = 'payment-receipts/'.Str::random(8).'.png';
        Storage::disk('local')->put($path, base64_decode(self::PNG));

        return PaymentReceipt::forceCreate(array_merge([
            'booking_id' => $booking->id,
            'image_url' => $path,
            'receipt_hash' => Str::random(64),
            'status' => 'pending',
            'uploaded_at' => now(),
        ], $overrides));
    }

    public function test_queue_lists_pending_receipts_oldest_first(): void
    {
        $newer = $this->receipt(overrides: ['uploaded_at' => now()->subMinutes(5)]);
        $older = $this->receipt(overrides: ['uploaded_at' => now()->subMinutes(30)]);
        $this->receipt(overrides: ['status' => 'verified']);

        $this->getJson('/api/v1/admin/payment-receipts?status=pending')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['total' => 2, 'page' => 1, 'per_page' => 20]])
            ->assertJsonPath('data.items.0.id', $older->id)
            ->assertJsonPath('data.items.1.id', $newer->id)
            ->assertJsonPath('data.items.0.status', 'pending')
            ->assertJsonStructure(['data' => ['items' => [[
                'id', 'booking_id', 'receipt_url', 'status', 'uploaded_at',
                'booking' => ['id', 'captain_name', 'total_price', 'venue_name_en', 'slot_date', 'start_time'],
            ]]]]);
    }

    public function test_queue_defaults_to_pending_and_validates_the_status_filter(): void
    {
        $this->receipt();
        $this->receipt(overrides: ['status' => 'rejected']);

        $this->getJson('/api/v1/admin/payment-receipts')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/admin/payment-receipts?status=lost')->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_receipt_image_is_only_served_through_the_signed_url(): void
    {
        $receipt = $this->receipt();
        $url = $this->getJson('/api/v1/admin/payment-receipts')->json('data.items.0.receipt_url');

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->getJson("/api/v1/admin/payment-receipts/{$receipt->id}/image")
            ->assertForbidden()
            ->assertJson(['success' => false]);

        $this->travel(PaymentReceiptResource::URL_LIFETIME_MINUTES + 1)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_admin_verifies_a_receipt_and_the_booking_is_confirmed(): void
    {
        $booking = $this->pendingBooking();
        $receipt = $this->receipt($booking);

        $response = $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'verified']);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Receipt verified, booking confirmed',
                'errors' => null,
                'data' => [
                    'id' => $receipt->id,
                    'status' => 'verified',
                    'verified_by' => $this->admin->id,
                    'booking' => ['id' => $booking->id, 'status' => 'confirmed', 'venue_name_en' => 'Green Field Court', 'start_time' => '18:00'],
                ],
            ])
            ->assertJsonStructure(['data' => ['verified_at']]);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('time_slots', ['id' => $booking->time_slot_id, 'status' => 'booked']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $booking->captain_user_id,
            'booking_id' => $booking->id,
            'type' => 'booking_confirmed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'receipt_verified',
            'target_type' => 'PAYMENT_RECEIPT',
            'target_id' => $receipt->id,
        ]);
    }

    public function test_verified_booking_shows_up_in_the_owners_weekly_income(): void
    {
        $booking = $this->pendingBooking();
        $receipt = $this->receipt($booking);
        $owner = $booking->timeSlot->venue->owner;
        $owner->assignRole('venue_owner');

        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'verified'])->assertOk();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/owner/bookings')->assertOk()->assertJsonPath('data.weekly_stats.income', 40);
    }

    public function test_receipt_already_used_for_another_booking_cannot_be_verified(): void
    {
        // receipt_hash is unique, so two stored receipts can't share a hash; the reuse
        // check is therefore stubbed here and its query is tested on its own below.
        $booking = $this->pendingBooking();
        $reused = $this->receipt($booking);
        $this->mock(ReceiptReuseCheck::class)
            ->shouldReceive('usedForAnotherBooking')
            ->once()
            ->withArgs(fn (PaymentReceipt $receipt) => $receipt->id === $reused->id)
            ->andReturnTrue();

        $this->putJson("/api/v1/admin/payment-receipts/{$reused->id}/verify", ['status' => 'verified'])
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'data' => null,
                'message' => 'This receipt has already been used for another booking.',
            ]);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'pending_payment']);
        $this->assertDatabaseHas('payment_receipts', ['id' => $reused->id, 'status' => 'pending']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_reuse_check_flags_a_hash_that_confirmed_a_different_booking(): void
    {
        $verified = $this->receipt(overrides: ['status' => 'verified']);
        $otherBooking = $this->pendingBooking(['start_time' => '20:00:00', 'end_time' => '21:00:00']);
        $check = new ReceiptReuseCheck;

        // An unsaved receipt carrying the same image hash for another booking.
        $this->assertTrue($check->usedForAnotherBooking(new PaymentReceipt(['receipt_hash' => $verified->receipt_hash, 'booking_id' => $otherBooking->id])));
        $this->assertFalse($check->usedForAnotherBooking(new PaymentReceipt(['receipt_hash' => $verified->receipt_hash, 'booking_id' => $verified->booking_id])));
        $this->assertFalse($check->usedForAnotherBooking($this->receipt($otherBooking)));
    }

    public function test_admin_rejects_a_receipt_with_a_reason_the_player_sees(): void
    {
        $this->freezeTime();
        $booking = $this->pendingBooking();
        $receipt = $this->receipt($booking);
        $reason = 'Receipt image is blurry, amount not readable';

        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'rejected', 'rejection_reason' => $reason])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Receipt rejected, player notified',
                'data' => ['id' => $receipt->id, 'status' => 'rejected', 'rejection_reason' => $reason],
                'errors' => null,
            ]);

        $this->assertDatabaseHas('payment_receipts', ['id' => $receipt->id, 'status' => 'rejected', 'rejection_reason' => $reason]);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'pending_payment']);
        $this->assertSame(now()->addMinutes(10)->toDateTimeString(), $booking->fresh()->hold_expires_at->toDateTimeString());
        $this->assertStringContainsString(
            $reason,
            $booking->notifications()->where('type', 'receipt_rejected')->sole()->body,
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'receipt_rejected', 'target_id' => $receipt->id]);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $receipt = $this->receipt();

        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.rejection_reason.0', 'Please enter the reason for rejecting this receipt.');

        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('payment_receipts', ['id' => $receipt->id, 'status' => 'pending']);
    }

    public function test_player_can_upload_a_new_receipt_after_a_rejection(): void
    {
        $player = $this->userWithRole('player');
        $booking = $this->makeBooking($this->makeSlot($this->makeVenue()), ['status' => 'pending_payment', 'captain_user_id' => $player->id]);
        $rejected = $this->receipt($booking);
        $this->putJson("/api/v1/admin/payment-receipts/{$rejected->id}/verify", ['status' => 'rejected', 'rejection_reason' => 'Wrong amount']);

        Sanctum::actingAs($player);
        $this->postJson("/api/v1/bookings/{$booking->id}/payment-receipt", [
            'receipt' => UploadedFile::fake()->createWithContent('transfer.png', base64_decode(self::PNG)),
        ])->assertCreated();

        $newReceipt = PaymentReceipt::where('booking_id', $booking->id)->where('status', 'pending')->sole();
        $this->assertSame(['local', $newReceipt->image_url], $newReceipt->imageLocation());
        Storage::disk('local')->assertExists($newReceipt->image_url);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_booking_without_a_new_receipt_expires_after_a_rejection(): void
    {
        $booking = $this->pendingBooking();
        $receipt = $this->receipt($booking);
        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'rejected', 'rejection_reason' => 'Wrong amount']);

        $this->travel(11)->minutes();
        $this->artisan('bookings:expire')->assertSuccessful();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('payment_receipts', ['id' => $receipt->id, 'status' => 'rejected']);
        $this->assertDatabaseHas('time_slots', ['id' => $booking->time_slot_id, 'status' => 'available']);
    }

    public function test_reviewed_receipts_cannot_be_reviewed_again(): void
    {
        $receipt = $this->receipt();
        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'verified'])->assertOk();

        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'rejected', 'rejection_reason' => 'Oops'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This receipt has already been reviewed.');
    }

    public function test_receipt_of_a_cancelled_booking_cannot_be_verified(): void
    {
        $booking = $this->pendingBooking();
        $receipt = $this->receipt($booking);
        $booking->update(['status' => 'cancelled']);

        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'verified'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This booking is no longer awaiting payment.');
    }

    public function test_unknown_receipt_returns_404(): void
    {
        $this->putJson('/api/v1/admin/payment-receipts/999/verify', ['status' => 'verified'])->assertNotFound();
    }

    public function test_non_admins_cannot_review_receipts(): void
    {
        $receipt = $this->receipt();
        $this->actingAsRole('venue_owner');

        $this->getJson('/api/v1/admin/payment-receipts')->assertForbidden();
        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'verified'])->assertForbidden();

        $this->assertDatabaseHas('payment_receipts', ['id' => $receipt->id, 'status' => 'pending']);
    }
}
