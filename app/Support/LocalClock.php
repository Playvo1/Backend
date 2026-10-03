<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\MessageBag;

/**
 * The current time in the platform's local timezone (config app.local_timezone).
 *
 * Slot dates and times are local wall-clock values, while stored timestamps such as
 * created_at are UTC; range queries on timestamps convert local boundaries with ->utc().
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

    /**
     * Adds a validation error when a slot on $date (Y-m-d) starting at $startTime
     * (HH:mm[:ss], local) is on a past day or has already started today.
     */
    public static function addPastSlotError(MessageBag $errors, string $date, string $startTime): void
    {
        $now = self::now();
        $today = $now->toDateString();

        if ($date < $today) {
            $errors->add('slot_date', 'The slot date must be today or later.');
        } elseif ($date === $today && substr($startTime, 0, 5) <= $now->format('H:i')) {
            $errors->add('start_time', 'This time has already passed today; pick a later time.');
        }
    }
}
