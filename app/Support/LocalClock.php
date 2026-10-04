<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The current time in the players' timezone (config app.local_timezone).
 *
 * Slot dates and times are local wall-clock values, while the app timezone and
 * stored timestamps such as created_at stay in UTC.
 */
class LocalClock
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.local_timezone'));
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }
}
