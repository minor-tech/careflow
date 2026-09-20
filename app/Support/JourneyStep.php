<?php

namespace App\Support;

use App\Enums\JourneyStepState;

/**
 * One stop on a patient's journey, as shown to them: a department, or for a
 * patient in a doctor's own line the step of being assigned to and seen by them.
 */
final readonly class JourneyStep
{
    /**
     * @param  string|null  $number  The patient's number here (e.g. "L-8"); only for the stop they are at now.
     */
    public function __construct(
        public string $department,
        public JourneyStepState $state,
        public ?string $number = null,
    ) {}
}
