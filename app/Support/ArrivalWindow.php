<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * When a patient at home is advised to arrive: a short stretch, because a
 * queue is a guess, not a timetable.
 */
final readonly class ArrivalWindow
{
    public function __construct(
        public Carbon $from,
        public Carbon $until,
    ) {}

    /**
     * "10:20–10:30 AM", in the clinic's own time; both ends get their AM/PM
     * only when they differ ("11:50 AM–12:10 PM").
     */
    public function label(): string
    {
        $from = $this->from->copy()->setTimezone(config('careflow.timezone'));
        $until = $this->until->copy()->setTimezone(config('careflow.timezone'));

        return $from->format('A') === $until->format('A')
            ? $from->format('g:i').'–'.$until->format('g:i A')
            : $from->format('g:i A').'–'.$until->format('g:i A');
    }
}
