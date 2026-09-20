<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which door a request came through. Staff review both on the same screen but
 * they mean different things: someone at home asking for a place in the queue,
 * or someone already standing in the facility registering on their own phone.
 */
enum RemoteRequestSource: string
{
    use HasOptions;

    case Remote = 'remote';
    case SelfCheckin = 'self_checkin';

    public function label(): string
    {
        return match ($this) {
            self::Remote => 'From home',
            self::SelfCheckin => 'Checked in on their own phone',
        };
    }

    /**
     * The way the visit that accepting it creates is recorded.
     */
    public function visitSource(): VisitSource
    {
        return match ($this) {
            self::Remote => VisitSource::Remote,
            self::SelfCheckin => VisitSource::SelfCheckin,
        };
    }
}
