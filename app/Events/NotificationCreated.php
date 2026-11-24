<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushes a new notification to its user in real time (private-App.Models.User.{id},
 * event "notification.created"). Sent during the request: there is no queue worker.
 */
class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Notification $notification)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * The same fields as one item of GET /notifications, plus the user's unread count.
     */
    public function broadcastWith(): array
    {
        $fresh = $this->notification->fresh() ?? $this->notification;

        return $fresh->toArray() + [
            'unread_count' => Notification::where('user_id', $fresh->user_id)
                ->where('is_read', false)
                ->count(),
        ];
    }
}
