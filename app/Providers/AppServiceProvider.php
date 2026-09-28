<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // AUDIT_LOG.target_type stores plain ERD entity names (e.g. "VENUE"), matching
        // the query examples in the API contract, so AuditLog::target() needs a morph
        // map instead of resolving target_type as a raw class name.
        Relation::enforceMorphMap([
            'USER' => User::class,
            'VENUE' => Venue::class,
            'BOOKING' => Booking::class,
            'PAYMENT_RECEIPT' => PaymentReceipt::class,
        ]);
    }
}
