<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\BuildsPlayvoData;
use Tests\TestCase;

/**
 * Notification list compatibility, read-all, the broadcast event's timing and payload,
 * channel auth edge cases, the broadcaster default and the migration.
 */
class NotificationsRealtimeTest extends TestCase
{
    use BuildsPlayvoData;
    use RefreshDatabase;

    private const ITEM_KEYS = [
        'id', 'user_id', 'booking_id', 'payment_receipt_id', 'type', 'title', 'body',
        'is_read', 'sent_at', 'created_at', 'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    protected function tearDown(): void
    {
        unset($_ENV['BROADCAST_CONNECTION'], $_SERVER['BROADCAST_CONNECTION']);
        putenv('BROADCAST_CONNECTION');

        parent::tearDown();
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

    // ---- List compatibility ----

    public function test_list_item_has_exactly_the_old_keys_plus_payment_receipt_id(): void
    {
        $user = $this->actingAsRole('player');
        $this->notify($user);

        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $item = $response->json('data.data.0');
        $this->assertEqualsCanonicalizing(self::ITEM_KEYS, array_keys($item));
        $this->assertNull($item['payment_receipt_id']);
        $this->assertFalse($item['is_read']);
        $this->assertSame(1, $response->json('data.unread_count'));
        $response->assertJsonStructure(['data' => ['current_page', 'data', 'per_page', 'total', 'last_page', 'next_page_url', 'unread_count']]);
    }

    public function test_status_filter_is_validated_and_kept_in_pagination_links(): void
    {
        $user = $this->actingAsRole('player');
        foreach (range(1, 22) as $i) {
            $this->notify($user, true);
        }
        $this->notify($user);

        $this->getJson('/api/v1/notifications?status=archived')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $response = $this->getJson('/api/v1/notifications?status=read')
            ->assertOk()
            ->assertJsonPath('data.total', 22)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonPath('data.unread_count', 1);
        $this->assertStringContainsString('status=read', $response->json('data.next_page_url'));

        $this->getJson('/api/v1/notifications?status=read&page=2')->assertJsonCount(2, 'data.data');
    }

    public function test_newest_first_with_a_stable_order_for_equal_timestamps(): void
    {
        $user = $this->actingAsRole('player');
        $this->travelTo(now()->startOfSecond());
        $first = $this->notify($user);
        $second = $this->notify($user);

        $this->getJson('/api/v1/notifications')
            ->assertJsonPath('data.data.0.id', $second->id)
            ->assertJsonPath('data.data.1.id', $first->id);
    }

    // ---- Read ----

    public function test_read_all_is_idempotent_and_scoped_to_the_user(): void
    {
        $user = $this->actingAsRole('player');
        $other = $this->userWithRole('player');
        $this->notify($user);
        $this->notify($user);
        $theirs = $this->notify($other);

        $this->putJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('data.unread_count', 0);

        $this->putJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 0);

        $this->assertFalse((bool) $theirs->fresh()->is_read);
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.unread_count', 0);
    }

    public function test_mark_one_rejects_non_numeric_and_other_users_ids(): void
    {
        $user = $this->actingAsRole('player');
        $theirs = $this->notify($this->userWithRole('player'));
        $mine = $this->notify($user);

        $this->putJson('/api/v1/notifications/abc/read')->assertNotFound()->assertJsonPath('success', false);
        $this->putJson("/api/v1/notifications/{$theirs->id}/read")->assertNotFound();
        $this->putJson("/api/v1/notifications/{$mine->id}/read")->assertOk();

        $this->assertTrue((bool) $mine->fresh()->is_read);
        $this->assertFalse((bool) $theirs->fresh()->is_read);
    }

    public function test_reading_does_not_broadcast(): void
    {
        $user = $this->actingAsRole('player');
        $notification = $this->notify($user);
        Event::fake([NotificationCreated::class]);

        $this->putJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
        $this->putJson('/api/v1/notifications/read-all')->assertOk();

        Event::assertNotDispatched(NotificationCreated::class);
    }

    // ---- Broadcast timing ----

    public function test_notification_in_a_rolled_back_transaction_is_never_broadcast(): void
    {
        $user = $this->userWithRole('player');
        Event::fake([NotificationCreated::class]);

        try {
            DB::transaction(function () use ($user) {
                $this->notify($user);

                throw new RuntimeException('Later step failed');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(0, Notification::count());
        Event::assertNotDispatched(NotificationCreated::class);
    }

    public function test_broadcast_waits_for_commit_and_fires_once(): void
    {
        $user = $this->userWithRole('player');
        Event::fake([NotificationCreated::class]);

        DB::transaction(function () use ($user) {
            $this->notify($user);
            Event::assertNotDispatched(NotificationCreated::class);
        });

        Event::assertDispatchedTimes(NotificationCreated::class, 1);
    }

    public function test_updating_a_notification_does_not_broadcast_again(): void
    {
        $notification = $this->notify($this->userWithRole('player'));
        Event::fake([NotificationCreated::class]);

        $notification->update(['title' => 'Changed', 'is_read' => true]);

        Event::assertNotDispatched(NotificationCreated::class);
    }

    // ---- Broadcast payload ----

    public function test_event_targets_only_the_recipient_with_the_list_item_and_count(): void
    {
        $user = $this->userWithRole('admin');
        $other = $this->userWithRole('admin');
        $this->notify($other);
        $this->notify($user, true);
        $this->notify($user);
        $notification = $this->notify($user, false, ['payment_receipt_id' => 77]);

        $event = new NotificationCreated($notification);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-App.Models.User.{$user->id}", $channels[0]->name);
        $this->assertSame('notification.created', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertEqualsCanonicalizing([...self::ITEM_KEYS, 'unread_count'], array_keys($payload));
        $this->assertSame($notification->id, $payload['id']);
        $this->assertSame($user->id, $payload['user_id']);
        $this->assertSame(77, $payload['payment_receipt_id']);
        $this->assertSame(2, $payload['unread_count']);

        // Same item as the list returns.
        Sanctum::actingAs($user);
        $listed = $this->getJson('/api/v1/notifications')->json('data.data.0');
        unset($payload['unread_count']);
        $this->assertEquals($listed, json_decode(json_encode($payload), true));
    }

    // ---- Channel auth ----

    private function usePusher(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
            'broadcasting.connections.pusher.options.cluster' => 'eu',
        ]);
        $this->app->make(BroadcastManager::class)->purge('pusher');
        require base_path('routes/channels.php');
    }

    public function test_pusher_js_form_request_without_token_gets_json_401(): void
    {
        $this->usePusher();

        // pusher-js posts form data and does not ask for JSON.
        $response = $this->post('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.1',
        ]);

        $response->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_unknown_or_malformed_channels_are_forbidden(): void
    {
        $this->usePusher();
        $user = $this->userWithRole('player');
        $token = $user->createToken('auth_token')->plainTextToken;

        foreach (['private-App.Models.User.abc', 'private-admins', 'private-App.Models.User.'.$user->id.'0'] as $channel) {
            $this->app['auth']->forgetGuards();
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->post('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
                ->assertStatus(403);
        }
    }

    public function test_auth_response_does_not_leak_the_secret(): void
    {
        $this->usePusher();
        $user = $this->userWithRole('player');
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$user->id}"])
            ->assertOk();

        $this->assertStringNotContainsString('test-secret', $response->getContent());
    }

    public function test_cors_preflight_allows_the_auth_endpoint(): void
    {
        $response = $this->call('OPTIONS', '/api/broadcasting/auth', server: [
            'HTTP_ORIGIN' => 'https://frontend-abc123-playvo.vercel.app',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, authorization',
        ]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    // ---- Broadcaster default ----

    public function test_empty_or_missing_broadcast_connection_means_null(): void
    {
        foreach (['', null] as $value) {
            if ($value === null) {
                unset($_ENV['BROADCAST_CONNECTION'], $_SERVER['BROADCAST_CONNECTION']);
                putenv('BROADCAST_CONNECTION');
            } else {
                $_ENV['BROADCAST_CONNECTION'] = $_SERVER['BROADCAST_CONNECTION'] = $value;
                putenv("BROADCAST_CONNECTION={$value}");
            }

            $config = require config_path('broadcasting.php');
            $this->assertSame('null', $config['default'], var_export($value, true));
        }
    }

    // ---- Migration ----

    public function test_migration_rolls_back_and_re_runs(): void
    {
        $migration = require database_path('migrations/2026_11_17_100000_add_payment_receipt_id_to_notifications_table.php');

        $this->assertTrue(Schema::hasColumn('notifications', 'payment_receipt_id'));
        $migration->up();

        $migration->down();
        $this->assertFalse(Schema::hasColumn('notifications', 'payment_receipt_id'));
        $migration->down();

        $migration->up();
        $this->assertTrue(Schema::hasColumn('notifications', 'payment_receipt_id'));
    }
}
