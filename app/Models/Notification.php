<?php

namespace App\Models;

use App\Events\NotificationCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'booking_id',
        'payment_receipt_id',
        'type',
        'title',
        'body',
        'is_read',
        'sent_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'sent_at' => 'datetime',
    ];

    /**
     * Every new notification is pushed to its user in real time, once the surrounding
     * transaction (if any) has committed. A broadcasting failure is logged and never
     * breaks the request that created the notification.
     */
    protected static function booted(): void
    {
        static::created(function (Notification $notification) {
            DB::afterCommit(function () use ($notification) {
                try {
                    event(new NotificationCreated($notification));
                } catch (\Throwable $e) {
                    Log::warning('Notification broadcast failed', [
                        'notification_id' => $notification->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        });
    }

    /**
     * The user this notification was sent to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The booking this notification relates to, if any.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    /**
     * The payment receipt this notification relates to, if any.
     */
    public function paymentReceipt(): BelongsTo
    {
        return $this->belongsTo(PaymentReceipt::class, 'payment_receipt_id');
    }
}
