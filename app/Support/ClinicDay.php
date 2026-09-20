<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * "Today" as a clinic sees it: the calendar day in Nairobi, not in the
 * application's UTC timezone. Queue numbers restart and the working queue
 * empties at local midnight.
 */
final class ClinicDay
{
    public static function now(): Carbon
    {
        return now(config('careflow.timezone'));
    }

    /**
     * Today's date in the clinic timezone, e.g. "2026-09-18".
     */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /**
     * The start (inclusive) and end (exclusive) of today, expressed in the
     * application timezone so they can be compared with stored timestamps.
     *
     * @return array{Carbon, Carbon}
     */
    public static function bounds(): array
    {
        $start = self::now()->startOfDay();

        return [
            $start->copy()->setTimezone(config('app.timezone')),
            $start->copy()->addDay()->setTimezone(config('app.timezone')),
        ];
    }
}
