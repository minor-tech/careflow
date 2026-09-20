<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum FacilityStatus: string
{
    use HasOptions;

    case PendingReview = 'pending_review';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * The status-pill tone that goes with this status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::PendingReview => 'wait',
            self::Active => 'ok',
            self::Suspended => 'danger',
            self::Rejected => 'danger',
        };
    }
}
