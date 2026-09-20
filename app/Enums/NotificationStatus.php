<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum NotificationStatus: string
{
    use HasOptions;

    /** Recorded, waiting for the queue worker to hand it to the gateway. */
    case Queued = 'queued';

    /** The gateway accepted it. (Not the same as delivered to the handset.) */
    case Sent = 'sent';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }

    /**
     * The status-pill tone that goes with this status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Queued => 'wait',
            self::Sent => 'ok',
            self::Failed => 'danger',
        };
    }
}
