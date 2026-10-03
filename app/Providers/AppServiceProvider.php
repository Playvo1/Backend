<?php

namespace App\Providers;

use App\Helpers\ApiResponse;
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

        // Guidelines 2.4: the assistant is limited per user to control Gemini cost and abuse.
        RateLimiter::for('assistant', function (Request $request) {
            $limit = config('services.assistant.hourly_limit');

            return Limit::perHour($limit)
                ->by('assistant:'.$request->user()->id)
                ->response(fn (Request $request, array $headers) => ApiResponse::send(
                    false,
                    429,
                    "You've reached the limit of {$limit} assistant questions per hour. Please try again later.",
                )->withHeaders($headers));
        });
    }
}
