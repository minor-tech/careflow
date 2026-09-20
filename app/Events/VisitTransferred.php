<?php

namespace App\Events;

use App\Models\Visit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A patient was sent on to another department, where they now wait.
 * Dispatched by the code that owns the change; listeners react to it once the
 * surrounding transaction has committed.
 */
class VisitTransferred
{
    use Dispatchable;

    public function __construct(public Visit $visit) {}
}
