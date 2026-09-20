<?php

namespace App\Exceptions;

use App\Enums\VisitStatus;
use App\Models\Visit;
use RuntimeException;

/**
 * A visit was asked to move to a status it can't move to from where it is now.
 * The message is written to be shown to staff as it is.
 */
class InvalidVisitTransition extends RuntimeException
{
    public function __construct(
        public readonly Visit $visit,
        public readonly VisitStatus $from,
        public readonly VisitStatus $to,
    ) {
        parent::__construct(
            "{$visit->loadMissing('department')->queueLabel()} is already {$from->label()}, so that can't be done. The queue shows the latest status."
        );
    }
}
