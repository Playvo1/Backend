<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\PaymentReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class ReviewReceiptSecurityTest extends TestCase
{
    use BuildsPlayvoData, RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->seedRoles();
    }

    private function uploadedReceipt(): PaymentReceipt
    {
        $player = $this->userWithRole('player');
        $booking = $this->makeBooking($this->makeSlot($this->makeVenue()), [
            'status' => 'pending_payment', 'captain_user_id' => $player->id, 'hold_expires_at' => now()->addMinutes(10),
        ]);

        Sanctum::actingAs($player);
        $this->post("/api/v1/bookings/{$booking->id}/payment-receipt", [
            'receipt' => UploadedFile::fake()->createWithContent('r.png', base64_decode(self::PNG)),
        ], ['Accept' => 'application/json'])->assertCreated();

        return PaymentReceipt::firstOrFail();
    }

    public function test_upload_goes_to_private_disk_and_not_public_storage(): void
    {
        $receipt = $this->uploadedReceipt();

        $this->assertStringStartsNotWith('http', $receipt->image_url);
        Storage::disk('local')->assertExists($receipt->image_url);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_receipt_is_not_reachable_through_public_storage_path(): void
    {
        $receipt = $this->uploadedReceipt();
        $this->app['auth']->forgetGuards();

        $status = $this->get('/storage/'.$receipt->image_url)->getStatusCode();
        $this->assertContains($status, [403, 404]);
    }

    public function test_unsigned_url_is_rejected(): void
    {
        $receipt = $this->uploadedReceipt();

        $this->getJson("/api/v1/admin/payment-receipts/{$receipt->id}/image")
            ->assertForbidden()
            ->assertJson(['success' => false, 'data' => null]);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $receipt = $this->uploadedReceipt();
        $url = URL::temporarySignedRoute('admin.payment-receipts.image', now()->addMinutes(15), ['paymentReceipt' => $receipt->id]);

        $this->getJson(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('a', 64), $url))->assertForbidden();
    }

    public function test_expired_signed_url_is_rejected(): void
    {
        $receipt = $this->uploadedReceipt();
        Sanctum::actingAs($this->userWithRole('admin'));
        $url = $this->getJson('/api/v1/admin/payment-receipts')->json('data.items.0.receipt_url');

        $this->get($url)->assertOk();

        $this->travel(16)->minutes();
        $this->getJson($url)->assertForbidden()->assertJson(['success' => false]);
    }

    public function test_signature_for_one_receipt_does_not_open_another(): void
    {
        $first = $this->uploadedReceipt();
        $second = PaymentReceipt::forceCreate([...$first->only(['booking_id', 'image_url', 'status', 'uploaded_at']), 'receipt_hash' => str_repeat('b', 64)]);
        $url = URL::temporarySignedRoute('admin.payment-receipts.image', now()->addMinutes(15), ['paymentReceipt' => $first->id]);

        $this->getJson(str_replace("/payment-receipts/{$first->id}/", "/payment-receipts/{$second->id}/", $url))
            ->assertStatus(403);
    }

    public function test_image_response_is_not_cacheable(): void
    {
        $receipt = $this->uploadedReceipt();
        $url = URL::temporarySignedRoute('admin.payment-receipts.image', now()->addMinutes(15), ['paymentReceipt' => $receipt->id]);

        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_reupload_after_rejection_is_allowed(): void
    {
        $receipt = $this->uploadedReceipt();
        $booking = Booking::find($receipt->booking_id);

        Sanctum::actingAs($this->userWithRole('admin'));
        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/verify", ['status' => 'rejected', 'rejection_reason' => 'Blurry'])->assertOk();

        Sanctum::actingAs($booking->captain);
        $other = base64_decode(self::PNG).'x';
        $this->post("/api/v1/bookings/{$booking->id}/payment-receipt", [
            'receipt' => UploadedFile::fake()->createWithContent('r2.png', $other),
        ], ['Accept' => 'application/json'])->assertCreated();
    }
}
