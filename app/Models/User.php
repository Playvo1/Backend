<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens;
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens , HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = [

    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The OTP / verification codes issued to this user.
     */
    public function verificationCodes(): HasMany
    {
        return $this->hasMany(VerificationCode::class, 'user_id');
    }

    /**
     * The venues owned by this user.
     */
    public function ownedVenues(): HasMany
    {
        return $this->hasMany(Venue::class, 'owner_id');
    }

    /**
     * The bookings made by this user as captain.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'captain_user_id');
    }

    /**
     * The device tokens registered by this user.
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class, 'user_id');
    }

    /**
     * The payment receipts this user has verified as an admin.
     */
    public function verifiedPaymentReceipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class, 'verified_by');
    }

    /**
     * The favorite records created by this user.
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class, 'user_id');
    }

    /**
     * The venues this user has favorited.
     */
    public function favoriteVenues(): BelongsToMany
    {
        return $this->belongsToMany(Venue::class, 'favorites', 'user_id', 'venue_id');
    }

    /**
     * The application (business) notifications sent to this user.
     */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'user_id');
    }

    /**
     * The assistant queries submitted by this user.
     */
    public function assistantQueries(): HasMany
    {
        return $this->hasMany(AssistantQuery::class, 'user_id');
    }

    /**
     * The audit log entries created by this user as an admin.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'admin_user_id');
    }
}
