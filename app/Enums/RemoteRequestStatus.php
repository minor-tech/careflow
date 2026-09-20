<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum RemoteRequestStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for review',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether the request is still waiting for a decision.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
