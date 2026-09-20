<?php

namespace App\Actions;

use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Events\VisitDoctorChanged;
use App\Exceptions\InvalidDoctorReassignment;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use App\Services\QueueNumberGenerator;
use Illuminate\Support\Facades\DB;

class ReassignVisitDoctor
{
    public function __construct(private QueueNumberGenerator $queueNumbers) {}

    /**
     * Hand a patient who hasn't been seen yet to a different doctor, and say
     * why. They join the new doctor's line at the back with its next number,
     * and the audit log records who moved them, from whom, to whom, and the
     * reason, so a routing decision can always be explained afterwards.
     *
     * Only while they are still waiting or have been called: once the
     * consultation has started it belongs to that doctor. A patient who had
     * been called goes back to waiting in the new line. A visit that somehow has
     * no doctor yet (from before the department assigned patients to doctors)
     * is simply assigned one here.
     *
     * Like every change to a visit, it works on the locked, re-read row, so two
     * people reassigning at once can't both win.
     *
     * @throws InvalidDoctorReassignment
     */
    public function handle(Visit $visit, User $newDoctor, string $reason, User $actor): Visit
    {
        return DB::transaction(function () use ($visit, $newDoctor, $reason, $actor): Visit {
            $current = Visit::whereKey($visit->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($current->status, [VisitStatus::AwaitingArrival, VisitStatus::Waiting, VisitStatus::Called], true)) {
                throw InvalidDoctorReassignment::consultationStarted();
            }

            if (! $current->department()->value('requires_doctor_assignment')) {
                throw InvalidDoctorReassignment::notDoctorQueue();
            }

            if ($current->isInDoctorQueue() && $current->assigned_doctor_id === $newDoctor->id) {
                throw InvalidDoctorReassignment::alreadyAssigned($newDoctor);
            }

            $isAvailable = User::query()
                ->availableDoctors($current->facility_id, $current->department_id)
                ->whereKey($newDoctor->id)
                ->exists();

            if (! $isAvailable) {
                throw InvalidDoctorReassignment::notAvailable($newDoctor);
            }

            $previousDoctorId = $current->isInDoctorQueue() ? $current->assigned_doctor_id : null;

            $current->update([
                'assigned_doctor_id' => $newDoctor->id,
                'doctor_queue_number' => $this->queueNumbers->next($current->facility_id, $current->department_id, $newDoctor->id),
                // Someone still on their way stays on their way: only a called patient goes back to waiting.
                'status' => $current->isAwaitingArrival() ? VisitStatus::AwaitingArrival : VisitStatus::Waiting,
                'department_entered_at' => now(),
                // A new line is a new chance to tell them they're nearly up.
                'almost_turn_notified' => false,
            ]);

            VisitEvent::create([
                'visit_id' => $current->id,
                'department_id' => $current->department_id,
                'event' => $previousDoctorId === null ? VisitEventType::DoctorAssigned : VisitEventType::DoctorReassigned,
                'user_id' => $actor->id,
                // Assigning for the first time has no "from", and is shaped like the assignment made at registration.
                'meta' => $previousDoctorId === null
                    ? ['doctor_id' => $newDoctor->id, 'reason' => $reason]
                    : ['from_doctor_id' => $previousDoctorId, 'to_doctor_id' => $newDoctor->id, 'reason' => $reason],
            ]);

            VisitDoctorChanged::dispatch($current);

            return $current;
        }, attempts: 3);
    }
}
