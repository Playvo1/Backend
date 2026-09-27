<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // Public auth endpoints: 10 requests/minute per IP and per account
        // (TEAM_GUIDELINES 2.4), so 6-digit codes can't be brute-forced.
        RateLimiter::for('auth', function (Request $request) {
            return [
                Limit::perMinute(10)->by('ip:'.$request->ip()),
                Limit::perMinute(10)->by('email:'.strtolower((string) $request->input('email'))),
            ];
        });
    }
}
