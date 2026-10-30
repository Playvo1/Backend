<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationListTest extends TestCase
{
    use RefreshDatabase;

    private function notify(User $user, bool $read): void
    {
        Notification::query()->create([
            'user_id' => $user->id,
            'type' => 'booking_confirmed',
            'title' => 'Title',
            'body' => 'Body',
            'is_read' => $read,
            'sent_at' => now(),
        ]);
    }

    public function test_the_list_stays_where_the_mobile_app_reads_it_with_the_unread_count_beside_it(): void
    {
        $user = User::factory()->create();
        $this->notify($user, false);
        $this->notify($user, false);
        $this->notify($user, true);

        $this->actingAs($user)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(3, 'data.data')
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_only_the_users_own_notifications_are_listed_and_counted(): void
    {
        $user = User::factory()->create();
        $this->notify(User::factory()->create(), false);

        $this->actingAs($user)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data.data')
            ->assertJsonPath('data.unread_count', 0);
    }
}
