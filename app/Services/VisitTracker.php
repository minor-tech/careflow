<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\TrackingTone;
use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Models\Visit;
use App\Support\ArrivalWindow;
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
        $visit->loadMissing(['facility', 'patient', 'department', 'assignedDoctor', 'remoteRequest']);

        // Only a patient in a doctor's own line is told whose it is: never how many other patients that doctor has.
        $doctorName = $visit->isInDoctorQueue() ? $visit->assignedDoctor?->doctorName() : null;

        // A patient on their way holds a place in the line too, so they are told where it is.
        $isWaiting = in_array($visit->status, [VisitStatus::Waiting, VisitStatus::AwaitingArrival], true);
        $ahead = $isWaiting ? $this->waits->patientsAhead($visit) : null;

        // A rating is only asked for once a visit is complete: never while it is under way, never after a cancellation.
        $isComplete = $visit->status === VisitStatus::Completed;
        $hasFeedback = $isComplete && $visit->feedback()->exists();

        return new TrackingSnapshot(
            visit: $visit,
            queueLabel: $visit->queueLabel(),
            stage: $this->stage($visit, $doctorName),
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
            doctorName: $doctorName,
            doctorChanged: $doctorName !== null && $this->wasHandedToAnotherDoctor($visit),
            awaitingArrival: $visit->isAwaitingArrival(),
            arrivalWindow: $visit->isAwaitingArrival() ? $this->arrivalWindow($visit) : null,
            arrivalSignaled: $visit->isAwaitingArrival() && $visit->arrival_signaled_at !== null,
        );
    }

    /**
     * The queue number of the patient being seen in this line now, if anyone
     * is: in the patient's own doctor's line if they are in one, otherwise in
     * the department's shared one, where the one who has been in the line
     * longest is named if several are. It is a number and nothing else, on
     * purpose.
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
            ->when(
                $visit->isInDoctorQueue(),
                fn ($line) => $line->where('assigned_doctor_id', $visit->assigned_doctor_id)->whereNotNull('doctor_queue_number'),
                fn ($line) => $line->whereNull('doctor_queue_number'),
            )
            ->orderByRaw('coalesce(department_entered_at, created_at)')
            ->orderBy('id')
            ->first(['id', 'department_id', 'assigned_doctor_id', 'queue_number', 'department_queue_number', 'doctor_queue_number']);

        // Same department as the patient's own, which is what the label's letter comes from.
        return $serving?->setRelation('department', $visit->department)->queueLabel();
    }

    private function stage(Visit $visit, ?string $doctorName): string
    {
        $department = $visit->department?->name;

        if ($visit->isAwaitingArrival()) {
            return $visit->arrival_signaled_at === null
                ? 'Waiting at home — come when it\'s your turn'
                : 'Waiting for the front desk to check you in';
        }

        // Told as their doctor's patient only while the visit is under way: a finished or cancelled one is told as any other is.
        if ($doctorName !== null && in_array($visit->status, [VisitStatus::Waiting, VisitStatus::Called, VisitStatus::InService], true)) {
            return match ($visit->status) {
                VisitStatus::Called => "It's your turn — please go to {$department} to see {$doctorName}",
                VisitStatus::InService => "Being seen by {$doctorName}",
                default => "Waiting for {$doctorName}",
            };
        }

        return match ($visit->status) {
            VisitStatus::Waiting, VisitStatus::WaitingDepartment => $department === null ? 'Waiting' : "Waiting for {$department}",
            VisitStatus::Called => $department === null ? "It's your turn" : "It's your turn — please go to {$department}",
            VisitStatus::InService => $department === null ? 'Being seen now' : "Being seen at {$department}",
            VisitStatus::Completed => 'Your visit is complete',
            VisitStatus::Cancelled => 'Your visit was cancelled',
        };
    }

    /**
     * Whether the last thing that happened to who this patient sees was a
     * handover from another doctor, so the page can say so until they are seen.
     */
    private function wasHandedToAnotherDoctor(Visit $visit): bool
    {
        $latest = $visit->events()
            ->whereIn('event', [VisitEventType::DoctorAssigned, VisitEventType::DoctorReassigned])
            ->reorder('id', 'desc')
            ->first();

        return $latest?->event === VisitEventType::DoctorReassigned;
    }

    /**
     * When they were advised to arrive, as it was worked out when they were accepted.
     */
    private function arrivalWindow(Visit $visit): ?string
    {
        $request = $visit->remoteRequest;

        if ($request?->recommended_arrival_from === null || $request->recommended_arrival_until === null) {
            return null;
        }

        return (new ArrivalWindow($request->recommended_arrival_from, $request->recommended_arrival_until))->label();
    }

    private function tone(Visit $visit): TrackingTone
    {
        return match ($visit->status) {
            VisitStatus::AwaitingArrival, VisitStatus::Waiting, VisitStatus::WaitingDepartment => TrackingTone::Waiting,
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
            return $visit->isAwaitingArrival()
                ? "You're almost up — please make your way to the facility now."
                : "You're almost up — please head back to the waiting area.";
        }

        return $visit->facility->usesChannel(NotificationChannel::Sms)
            ? "You don't need to wait here — we'll text you when you're about to be called."
            : null;
    }
}
