<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum VisitStatus: string
{
    use HasOptions;

    case Waiting = 'waiting';
    case Called = 'called';
    case InService = 'in_service';
    case WaitingDepartment = 'waiting_department';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting',
            self::Called => 'Called',
            self::InService => 'In service',
            self::WaitingDepartment => 'Waiting for department',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The status-pill tone that goes with this status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Waiting => 'wait',
            self::Called => 'info',
            self::InService => 'ok',
            self::WaitingDepartment => 'wait',
            self::Completed => 'muted',
            self::Cancelled => 'muted',
        };
    }
}
