<?php

namespace App\Enums;

/**
 * The colour of the status line on the tracking page: amber while the patient
 * waits, green when it is their turn or they are done, red when cancelled.
 */
enum TrackingTone: string
{
    case Waiting = 'waiting';
    case Ready = 'ready';
    case Cancelled = 'cancelled';

    public function dotClass(): string
    {
        return match ($this) {
            self::Waiting => 'bg-wait',
            self::Ready => 'bg-ok',
            self::Cancelled => 'bg-danger',
        };
    }
}
