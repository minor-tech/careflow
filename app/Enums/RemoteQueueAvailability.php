<?php

namespace App\Enums;

/**
 * Whether a facility is taking remote queue requests right now. Worked out
 * live from its settings and today's requests, never stored.
 */
enum RemoteQueueAvailability: string
{
    case Off = 'off';
    case Closed = 'closed';
    case Full = 'full';
    case Open = 'open';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    /**
     * What a patient is told when it is not open, or null when it is (or
     * simply isn't offered, which needs no explaining).
     */
    public function reason(): ?string
    {
        return match ($this) {
            self::Closed => 'Remote queue requests have closed for today.',
            self::Full => 'The remote queue is full right now.',
            self::Off, self::Open => null,
        };
    }
}
