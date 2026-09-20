<?php

namespace App\Support;

use App\Enums\TrackingTone;
use App\Models\Visit;

/**
 * Everything the tracking page shows for one visit at one moment. Built by
 * App\Services\VisitTracker and deliberately made of the patient's own
 * details and bare queue numbers only: nothing about anyone else.
 */
final readonly class TrackingSnapshot
{
    /**
     * @param  int|null  $patientsAhead  Only while waiting; otherwise the question doesn't apply.
     * @param  string|null  $serving  The queue number now being served, e.g. "#21" or "L-8". A number, never a name.
     * @param  list<JourneyStep>  $steps
     * @param  bool  $askForFeedback  The visit is complete and hasn't been rated yet.
     * @param  bool  $feedbackGiven  The visit is complete and has been rated.
     */
    public function __construct(
        public Visit $visit,
        public string $queueLabel,
        public string $stage,
        public TrackingTone $tone,
        public ?int $patientsAhead,
        public ?string $serving,
        public ?WaitEstimate $estimate,
        public ?string $reassurance,
        public array $steps,
        public bool $open,
        public bool $askForFeedback,
        public bool $feedbackGiven,
    ) {}
}
