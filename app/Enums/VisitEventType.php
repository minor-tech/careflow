<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use InvalidArgumentException;

/**
 * What happened, as written to a visit's audit log. Names describe the step,
 * not the resulting status, so "in_service" is logged as "started".
 */
enum VisitEventType: string
{
    use HasOptions;

    case Registered = 'registered';
    case Called = 'called';
    case Started = 'started';
    case Recalled = 'recalled';
    case Completed = 'completed';
    case Transferred = 'transferred';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::Called => 'Called',
            self::Started => 'Started',
            self::Recalled => 'Recalled',
            self::Completed => 'Completed',
            self::Transferred => 'Transferred',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The event to log for a status change. Assumes the change is allowed.
     */
    public static function forTransition(VisitStatus $from, VisitStatus $to): self
    {
        return match ($to) {
            VisitStatus::Called => self::Called,
            VisitStatus::InService => self::Started,
            VisitStatus::Waiting => self::Recalled,
            VisitStatus::Completed => self::Completed,
            VisitStatus::Cancelled => self::Cancelled,
            // Moving to another department is a transfer, which has its own
            // operation and its own event; it is never a plain status change.
            VisitStatus::WaitingDepartment => throw new InvalidArgumentException('A visit changes department by being transferred, not by a status change.'),
        };
    }
}
