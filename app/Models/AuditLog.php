<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'admin_user_id',
        'action',
        'target_type',
        'target_id',
    ];

    /**
     * Records an admin action against an ERD entity (target_type is a morph-map key such as "VENUE").
     */
    public static function record(int $adminUserId, string $action, string $targetType, int $targetId): self
    {
        return static::create([
            'admin_user_id' => $adminUserId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);
    }

    /**
     * The admin user who performed this action.
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * The model this audit log entry targets (target_type / target_id).
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
