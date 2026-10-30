<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\PaymentReceipt;
use App\Models\TimeSlot;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentReceiptReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, CitySeeder::class, SportSeeder::class, DemoDataSeeder::class]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->player = User::factory()->create();
        $this->player->assignRole('player');
    }

    /**
     * A booking made through the API, so the slot is held exactly as it is for a player.
     */
    private function bookFreeSlot(): Booking
    {
        $slot = TimeSlot::query()->where('status', 'available')->firstOrFail();

        $bookingId = $this->actingAs($this->player)
            ->postJson('/api/v1/bookings', [
                'time_slot_id' => $slot->id,
                'captain_name' => 'Captain',
                'captain_role' => 'captain',
                'captain_phone' => '0599000000',
            ])
            ->assertCreated()
            ->json('data.booking_id');

        return Booking::query()->findOrFail($bookingId);
    }

    private function receiptFor(Booking $booking): PaymentReceipt
    {
        return PaymentReceipt::query()->create([
            'booking_id' => $booking->id,
            'image_url' => 'https://example.test/receipt.jpg',
            'receipt_hash' => Str::random(40),
            'status' => 'pending',
            'uploaded_at' => now(),
        ]);
    }

    public function test_a_new_booking_holds_its_slot(): void
    {
        $booking = $this->bookFreeSlot();

        $this->assertSame('blocked', $booking->timeSlot->status);
    }

    public function test_verifying_a_receipt_confirms_the_booking_and_books_the_slot(): void
    {
        $booking = $this->bookFreeSlot();
        $receipt = $this->receiptFor($booking);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify")
            ->assertOk();

        $this->assertSame('verified', $receipt->fresh()->status);
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame('booked', $booking->fresh()->timeSlot->status);
    }

    public function test_a_receipt_is_not_verified_when_its_slot_is_no_longer_held(): void
    {
        $booking = $this->bookFreeSlot();
        $receipt = $this->receiptFor($booking);
        $booking->timeSlot->update(['status' => 'available']);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify")
            ->assertStatus(422);

        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertSame('pending_payment', $booking->fresh()->status);
    }

    public function test_a_receipt_is_not_verified_when_its_booking_was_cancelled(): void
    {
        $booking = $this->bookFreeSlot();
        $receipt = $this->receiptFor($booking);
        $booking->update(['status' => 'cancelled']);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify")
            ->assertStatus(422);

        $this->assertSame('pending', $receipt->fresh()->status);
    }

    public function test_rejecting_a_receipt_cancels_the_booking_and_frees_the_slot(): void
    {
        $booking = $this->bookFreeSlot();
        $receipt = $this->receiptFor($booking);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/reject", ['reason' => 'Unreadable'])
            ->assertOk();

        $this->assertSame('rejected', $receipt->fresh()->status);
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame('available', $booking->fresh()->timeSlot->status);
    }
}
