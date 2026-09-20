<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a visit came to be in the queue. Every source ends up in the same
 * queue, tracking page and PIN system: this only records the way in.
 */
enum VisitSource: string
{
    use HasOptions;

    case WalkIn = 'walk_in';
    case Remote = 'remote';
    case Appointment = 'appointment';
    case SelfCheckin = 'self_checkin';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Walk-in',
            self::Remote => 'Remote request',
            self::Appointment => 'Appointment',
            self::SelfCheckin => 'Self check-in',
        };
    }
}
