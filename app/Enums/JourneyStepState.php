<?php

namespace App\Enums;

enum JourneyStepState: string
{
    /** The patient has been through this department and moved on (or finished). */
    case Done = 'done';

    /** Where the patient is now. */
    case Current = 'current';

    /** The visit ended here without being completed. */
    case Cancelled = 'cancelled';
}
