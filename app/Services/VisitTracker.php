<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\TrackingTone;
use App\Enums\VisitStatus;
use App\Models\Visit;
use App\Support\TrackingSnapshot;

/**
 * Works out what a patient sees on their tracking page.
 *
 * Only ever reads the visit it is given, plus a bare queue number for
 * whoever is being served in the same department. Names and phone numbers of
 * other patients are never selected, so they cannot leak into the page.
 */
class VisitTracker
{
    public function __construct(
        private WaitEstimator $waits,
        private VisitJourney $journey,
    ) {}

    public function snapshot(Visit $visit): TrackingSnapshot
    {
        $visit->loadMissing(['facility', 'patient', 'department']);

        $isWaiting = $visit->status === VisitStatus::Waiting;
        $ahead = $isWaiting ? $this->waits->patientsAhead($visit) : null;

        // A rating is only asked for once a visit is complete: never while it is under way, never after a cancellation.
        $isComplete = $visit->status === VisitStatus::Completed;
        $hasFeedback = $isComplete && $visit->feedback()->exists();

        return new TrackingSnapshot(
            visit: $visit,
            queueLabel: $visit->queueLabel(),
            stage: $this->stage($visit),
            tone: $this->tone($visit),
            patientsAhead: $ahead,
            serving: $isWaiting ? $this->servingNow($visit) : null,
            // The estimate is secondary to the count above it: the count is a fact, this is a guess.
            estimate: $ahead === null ? null : $this->waits->estimate($visit, $ahead),
            reassurance: $ahead === null ? null : $this->reassurance($visit, $ahead),
            steps: $this->journey->steps($visit),
            open: $this->journey->isOpen($visit),
            askForFeedback: $isComplete && ! $hasFeedback,
            feedbackGiven: $hasFeedback,
        );
    }

    /**
     * The queue number of the patient being seen in this department now, if
     * anyone is. If several are (more than one doctor), the one who has been
     * in the line longest. It is a number and nothing else, on purpose.
     */
    private function servingNow(Visit $visit): ?string
    {
        if ($visit->department_id === null) {
            return null;
        }

        $serving = Visit::query()
            ->where('facility_id', $visit->facility_id)
            ->where('department_id', $visit->department_id)
            ->where('status', VisitStatus::InService)
            ->registeredToday()
            ->orderByRaw('coalesce(department_entered_at, created_at)')
            ->orderBy('id')
            ->first(['id', 'department_id', 'queue_number', 'department_queue_number']);

        // Same department as the patient's own, which is what the label's letter comes from.
        return $serving?->setRelation('department', $visit->department)->queueLabel();
    }

    private function stage(Visit $visit): string
    {
        $department = $visit->department?->name;

        return match ($visit->status) {
            VisitStatus::Waiting, VisitStatus::WaitingDepartment => $department === null ? 'Waiting' : "Waiting for {$department}",
            VisitStatus::Called => $department === null ? "It's your turn" : "It's your turn — please go to {$department}",
            VisitStatus::InService => $department === null ? 'Being seen now' : "Being seen at {$department}",
            VisitStatus::Completed => 'Your visit is complete',
            VisitStatus::Cancelled => 'Your visit was cancelled',
        };
    }

    private function tone(Visit $visit): TrackingTone
    {
        return match ($visit->status) {
            VisitStatus::Waiting, VisitStatus::WaitingDepartment => TrackingTone::Waiting,
            VisitStatus::Called, VisitStatus::InService, VisitStatus::Completed => TrackingTone::Ready,
            VisitStatus::Cancelled => TrackingTone::Cancelled,
        };
    }

    /**
     * Tells a patient they can step away, and when to come back. The first
     * line is a promise that a text will arrive, so it is only made where
     * texts are actually sent.
     */
    private function reassurance(Visit $visit, int $ahead): ?string
    {
        if ($ahead <= AlmostTurnNotifier::NEAR_THE_FRONT) {
            return "You're almost up — please head back to the waiting area.";
        }

        return $visit->facility->usesChannel(NotificationChannel::Sms)
            ? "You don't need to wait here — we'll text you when you're about to be called."
            : null;
    }
}
