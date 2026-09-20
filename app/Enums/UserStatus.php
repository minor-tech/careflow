<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum UserStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * The status-pill tone that goes with this status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Active => 'ok',
            self::Suspended => 'danger',
        };
    }
}
