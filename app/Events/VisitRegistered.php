<?php

namespace App\Events;

use App\Models\Visit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A patient was registered and put in the queue.
 * Dispatched by the code that owns the change; listeners react to it once the
 * surrounding transaction has committed.
 */
class VisitRegistered
{
    use Dispatchable;

    public function __construct(public Visit $visit) {}
}
