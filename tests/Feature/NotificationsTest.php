<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\PaymentReceipt;
use App\Models\User;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use BuildsPlayvoData;
    use RefreshDatabase;

    // A valid 1x1 PNG.
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function notify(User $user, bool $read = false, array $extra = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id,
            'type' => 'booking_confirmed',
            'title' => 'Title',
            'body' => 'Body',
            'is_read' => $read,
            'sent_at' => now(),
        ], $extra));
    }

    private function mockCloudinary(): void
    {
        $api = Mockery::mock();
        $api->shouldReceive('upload')->andReturn([
            'secure_url' => 'https://res.cloudinary.com/demo/image/upload/v1/playvo/payment-receipts/r.png',
            'public_id' => 'playvo/payment-receipts/r',
        ]);
        $api->shouldReceive('destroy')->andReturn(['result' => 'ok']);

        $cloudinary = Mockery::mock();
        $cloudinary->shouldReceive('uploadApi')->andReturn($api);
        $this->instance(\Cloudinary\Cloudinary::class, $cloudinary);
        Cloudinary::clearResolvedInstance(\Cloudinary\Cloudinary::class);
    }

    private function receiptFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png').'.png';
        file_put_contents($path, base64_decode(self::PNG));

        return new UploadedFile($path, 'receipt.png', 'image/png', null, true);
    }

    /** A pending booking made by the player through the API. */
    private function pendingBooking(User $player): Booking
    {
        $slot = $this->makeSlot($this->makeVenue());

        Sanctum::actingAs($player);
        $id = $this->postJson('/api/v1/bookings', [
            'time_slot_id' => $slot->id,
            'captain_name' => 'Captain',
            'captain_role' => 'captain',
            'captain_phone' => '0599000000',
        ])->assertCreated()->json('data.booking_id');

        return Booking::findOrFail($id);
    }

    private function uploadReceipt(User $player, Booking $booking)
    {
        $this->mockCloudinary();
        Sanctum::actingAs($player);

        return $this->post("/api/v1/bookings/{$booking->id}/payment-receipt", [
            'receipt' => $this->receiptFile(),
        ], ['Accept' => 'application/json']);
    }

    // ---- List and read-all ----

    public function test_list_can_be_filtered_by_status_and_unread_count_stays_total(): void
    {
        $user = $this->userWithRole('admin');
        $unread = [$this->notify($user)->id, $this->notify($user)->id];
        $read = $this->notify($user, true)->id;
        $this->notify($this->userWithRole('admin'));

        Sanctum::actingAs($user);

        $ids = fn (string $query) => collect($this->getJson('/api/v1/notifications'.$query)->assertOk()->json('data.data'))
            ->pluck('id')->sort()->values()->all();

        $this->assertSame([...$unread, $read], $ids(''));
        $this->assertSame($unread, $ids('?status=unread'));
        $this->assertSame([$read], $ids('?status=read'));

        $this->getJson('/api/v1/notifications?status=read')
            ->assertJsonPath('data.unread_count', 2)
            ->assertJsonPath('data.total', 1);

        $this->getJson('/api/v1/notifications?status=archived')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_read_all_marks_only_the_users_unread_notifications(): void
    {
        $user = $this->userWithRole('venue_owner');
        $other = $this->userWithRole('venue_owner');
        $this->notify($user);
        $this->notify($user);
        $this->notify($user, true);
        $foreign = $this->notify($other);

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => ['updated' => 2, 'unread_count' => 0],
                'message' => 'All notifications marked as read.',
                'errors' => null,
            ]);

        $this->assertSame(0, Notification::where('user_id', $user->id)->where('is_read', false)->count());
        $this->assertFalse($foreign->fresh()->is_read);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.unread_count', 0);

        $this->putJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 0);
    }

    public function test_marking_one_notification_still_works(): void
    {
        $user = $this->userWithRole('player');
        $notification = $this->notify($user);

        Sanctum::actingAs($user);

        $this->putJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);
    }

    public function test_read_all_requires_authentication(): void
    {
        $this->putJson('/api/v1/notifications/read-all')->assertStatus(401);
    }

    // ---- Receipt links ----

    public function test_receipt_upload_notifies_admins_with_the_receipt_id(): void
    {
        $admin = $this->userWithRole('admin');
        $player = $this->userWithRole('player');
        $booking = $this->pendingBooking($player);

        $receiptId = $this->uploadReceipt($player, $booking)->assertCreated()->json('data.receipt_id');

        $notification = Notification::where('user_id', $admin->id)->firstOrFail();
        $this->assertSame('payment_receipt_uploaded', $notification->type);
        $this->assertSame($receiptId, $notification->payment_receipt_id);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/notifications')
            ->assertJsonPath('data.data.0.payment_receipt_id', $receiptId)
            ->assertJsonPath('data.data.0.booking_id', $booking->id);
    }

    public function test_receipt_rejection_notifies_the_player_with_the_receipt_id(): void
    {
        $admin = $this->userWithRole('admin');
        $player = $this->userWithRole('player');
        $booking = $this->pendingBooking($player);
        $receipt = PaymentReceipt::create([
            'booking_id' => $booking->id,
            'image_url' => 'https://example.test/receipt.jpg',
            'receipt_hash' => Str::random(40),
            'status' => 'pending',
            'uploaded_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/admin/payment-receipts/{$receipt->id}/reject", ['reason' => 'Unreadable'])
            ->assertOk();

        $notification = Notification::where('user_id', $player->id)->firstOrFail();
        $this->assertSame('payment_receipt_rejected', $notification->type);
        $this->assertSame($receipt->id, $notification->payment_receipt_id);
    }

    public function test_other_notifications_have_no_receipt_id(): void
    {
        $user = $this->userWithRole('player');
        $this->notify($user);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.data.0.payment_receipt_id', null);
    }

    // ---- Real-time event ----

    public function test_creating_a_notification_dispatches_the_broadcast_event(): void
    {
        Event::fake([NotificationCreated::class]);

        $user = $this->userWithRole('admin');
        $this->notify($user, true);
        $notification = $this->notify($user, false, ['payment_receipt_id' => 7]);

        Event::assertDispatched(NotificationCreated::class, 2);
        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($notification, $user) {
            if ($event->notification->id !== $notification->id) {
                return false;
            }

            $channels = $event->broadcastOn();
            $payload = $event->broadcastWith();

            Sanctum::actingAs($user);
            $item = collect($this->getJson('/api/v1/notifications')->json('data.data'))->firstWhere('id', $notification->id);

            return count($channels) === 1
                && $channels[0]->name === "private-App.Models.User.{$user->id}"
                && $event->broadcastAs() === 'notification.created'
                && $payload['unread_count'] === 1
                && $payload['payment_receipt_id'] === 7
                && array_diff_key($payload, ['unread_count' => true]) == $item;
        });
    }

    public function test_receipt_upload_broadcasts_to_each_admin(): void
    {
        Event::fake([NotificationCreated::class]);

        $first = $this->userWithRole('admin');
        $second = $this->userWithRole('admin');
        $player = $this->userWithRole('player');
        $booking = $this->pendingBooking($player);

        $this->uploadReceipt($player, $booking)->assertCreated();

        Event::assertDispatched(NotificationCreated::class, 2);
        foreach ([$first, $second] as $admin) {
            Event::assertDispatched(
                NotificationCreated::class,
                fn (NotificationCreated $event) => $event->broadcastOn()[0]->name === "private-App.Models.User.{$admin->id}"
                    && $event->notification->type === 'payment_receipt_uploaded'
            );
        }
    }

    public function test_a_failing_broadcaster_does_not_break_the_receipt_upload(): void
    {
        Log::spy();

        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')->andThrow(new BroadcastException('Pusher is down'));
        Broadcast::extend('failing', fn () => $broadcaster);
        config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);

        $admin = $this->userWithRole('admin');
        $player = $this->userWithRole('player');
        $booking = $this->pendingBooking($player);

        $this->uploadReceipt($player, $booking)
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertSame(1, Notification::where('user_id', $admin->id)->count());
        $this->assertSame(1, PaymentReceipt::count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => $message === 'Notification broadcast failed')
            ->once();
    }
}
