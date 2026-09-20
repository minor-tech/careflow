<?php

namespace App\Events;

use App\Models\Visit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A waiting patient was handed from one doctor to another, so they are now in
 * a different line. Listeners react once the surrounding transaction commits.
 */
class VisitDoctorChanged
{
    use Dispatchable;

    public function __construct(public Visit $visit) {}
}
