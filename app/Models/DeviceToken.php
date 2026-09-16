<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceToken extends Model
{
    /**
     * The user this device token belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The sync status records for this device token.
     */
    public function syncStatuses(): HasMany
    {
        return $this->hasMany(SyncStatus::class, 'device_token_id');
    }
}
