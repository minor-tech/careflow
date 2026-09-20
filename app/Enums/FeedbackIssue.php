<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What a patient can say went wrong, when they rate a visit 3 stars or fewer.
 */
enum FeedbackIssue: string
{
    use HasOptions;

    case LongWait = 'long_wait';
    case Staff = 'staff';
    case Billing = 'billing';
    case Doctor = 'doctor';
    case Laboratory = 'laboratory';
    case Pharmacy = 'pharmacy';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LongWait => 'Long wait',
            self::Staff => 'Staff',
            self::Billing => 'Billing',
            self::Doctor => 'Doctor',
            self::Laboratory => 'Laboratory',
            self::Pharmacy => 'Pharmacy',
            self::Other => 'Other',
        };
    }
}
