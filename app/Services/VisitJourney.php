<?php

namespace App\Services;

use App\Enums\JourneyStepState;
use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Models\Visit;
use App\Support\JourneyStep;

/**
 * Turns a visit's audit log into the short list of departments a patient has
 * been through, for showing them where they are.
 *
 * It only ever lists departments the patient has actually reached. Where they
 * go next isn't decided in advance (a doctor decides, department by
 * department), so nothing beyond the current stop is predicted.
 */
class VisitJourney
{
    /**
     * The stops in order, oldest first. A new stop starts at registration and
     * at every transfer, so coming back to a department later is a separate
     * stop rather than being folded into the earlier one.
     *
     * @return list<JourneyStep>
     */
    public function steps(Visit $visit): array
    {
        $legs = [];

        foreach ($visit->events()->with('department:id,name')->get() as $event) {
            if ($legs === [] || $event->event === VisitEventType::Transferred) {
                $legs[] = $event->department?->name ?? 'Registration';
            }
        }

        // A visit with no log at all: show at least where it is now.
        if ($legs === []) {
            $legs[] = $visit->department?->name ?? 'Registration';
        }

        $lastIndex = array_key_last($legs);

        $steps = array_map(
            fn (string $name, int $index): JourneyStep => $index < $lastIndex
                ? new JourneyStep($name, JourneyStepState::Done)
                : $this->lastStep($visit, $name),
            $legs,
            array_keys($legs),
        );

        return $this->isOpen($visit) && $visit->isInDoctorQueue()
            ? $this->withDoctor($visit, $steps, registeredHere: count($legs) === 1)
            : $steps;
    }

    /**
     * For a patient in one doctor's line, the stop they are at is told as a
     * doctor: assigned, then waiting for, called by, or with that doctor. If
     * they registered straight into that department there is no earlier stop
     * to show, so registering is shown as the first step.
     *
     * @param  list<JourneyStep>  $steps
     * @return list<JourneyStep>
     */
    private function withDoctor(Visit $visit, array $steps, bool $registeredHere): array
    {
        $doctor = $visit->loadMissing('assignedDoctor')->assignedDoctor?->doctorName() ?? 'your doctor';

        array_pop($steps);

        if ($registeredHere) {
            $steps[] = new JourneyStep('Registered', JourneyStepState::Done);
        }

        $steps[] = new JourneyStep("Doctor assigned: {$doctor}", JourneyStepState::Done);
        $steps[] = new JourneyStep(
            match ($visit->status) {
                VisitStatus::Called => "Called by {$doctor}",
                VisitStatus::InService => "With {$doctor}",
                default => "Waiting for {$doctor}",
            },
            JourneyStepState::Current,
            $visit->loadMissing('department')->queueLabel(),
        );

        return $steps;
    }

    /**
     * Whether more of the journey may still follow, i.e. the visit isn't over.
     */
    public function isOpen(Visit $visit): bool
    {
        return ! in_array($visit->status, [VisitStatus::Completed, VisitStatus::Cancelled], true);
    }

    private function lastStep(Visit $visit, string $name): JourneyStep
    {
        return match ($visit->status) {
            VisitStatus::Completed => new JourneyStep($name, JourneyStepState::Done),
            VisitStatus::Cancelled => new JourneyStep($name, JourneyStepState::Cancelled),
            default => new JourneyStep($name, JourneyStepState::Current, $visit->loadMissing('department')->queueLabel()),
        };
    }
}
