<?php

namespace App\Services;

use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Events\VisitCalled;
use App\Events\VisitCompleted;
use App\Events\VisitTransferred;
use App\Exceptions\InvalidDepartmentTransfer;
use App\Exceptions\InvalidVisitTransition;
use App\Models\Department;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use Illuminate\Support\Facades\DB;

/**
 * The one place a visit's status changes. It owns the rules for which moves
 * are allowed and writes the audit-log entry for every move, so no status is
 * ever changed without a record of who changed it and when.
 */
class VisitStatusTransitioner
{
    /**
     * Where a visit can go from each status, within its department. Anything
     * not listed is refused. Completed and cancelled visits are final.
     *
     * called -> waiting is the "recall": a patient who didn't respond goes back
     * in the queue. called -> cancelled is the no-show. Moving a patient on to
     * another department is not a status change, so it is not here: see
     * transferToDepartment().
     *
     * @var array<string, list<VisitStatus>>
     */
    private const ALLOWED = [
        // Someone accepted from home is checked in (see RemoteArrival), never called: all that can happen to them here is a no-show.
        'awaiting_arrival' => [VisitStatus::Cancelled],
        'waiting' => [VisitStatus::Called, VisitStatus::Cancelled],
        'called' => [VisitStatus::InService, VisitStatus::Waiting, VisitStatus::Cancelled],
        'in_service' => [VisitStatus::Completed],
    ];

    public function __construct(private QueueNumberGenerator $queueNumbers) {}

    public function canTransition(VisitStatus $from, VisitStatus $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value] ?? [], true);
    }

    /**
     * Move a visit to a new status and log it.
     *
     * The visit row is locked and re-read first, so the rules are checked
     * against what is actually in the database, not a stale copy: if two staff
     * press "Call" on the same patient at once, one succeeds and the other is
     * refused. The status change and its log entry succeed or fail together.
     *
     * @throws InvalidVisitTransition
     */
    public function transition(Visit $visit, VisitStatus $to, User $actor): Visit
    {
        return DB::transaction(function () use ($visit, $to, $actor): Visit {
            $current = Visit::whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
            $from = $current->status;

            if (! $this->canTransition($from, $to)) {
                throw new InvalidVisitTransition($current, $from, $to);
            }

            $current->status = $to;

            if ($to === VisitStatus::Completed) {
                $current->completed_at = now();
            }

            $current->save();

            VisitEvent::create([
                'visit_id' => $current->id,
                'department_id' => $current->department_id,
                'event' => VisitEventType::forTransition($from, $to),
                'user_id' => $actor->id,
            ]);

            // Reactions (the patient's SMS) wait for this transaction to commit.
            match ($to) {
                VisitStatus::Called => VisitCalled::dispatch($current),
                VisitStatus::Completed => VisitCompleted::dispatch($current),
                default => null,
            };

            return $current;
        });
    }

    /**
     * Send a patient who has been seen to another department, where they join
     * the queue afresh: waiting, with that department's own next queue number.
     * Their registration number (queue_number) is never touched.
     *
     * Only a patient being served can be sent onward; they have to have been
     * seen here first. Like transition(), it works on the locked, re-read
     * visit, and the move, the new number and the log entry all succeed or fail
     * together.
     *
     * Where the destination gives each patient their own doctor, one has to be
     * chosen (and be on duty there): the patient joins that doctor's own line
     * with its own number instead of the department's shared one. Leaving such
     * a department clears that place in the line, but the visit keeps the
     * doctor it had, as history.
     *
     * @throws InvalidVisitTransition when the patient isn't in service
     * @throws InvalidDepartmentTransfer when the destination is the current department, inactive, another facility's, or needs a doctor who isn't given or isn't on duty
     */
    public function transferToDepartment(Visit $visit, Department $to, User $actor, ?User $doctor = null): Visit
    {
        // Retried if MySQL picks this as a deadlock victim; nothing has been committed by then.
        return DB::transaction(function () use ($visit, $to, $actor, $doctor): Visit {
            $current = Visit::whereKey($visit->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status !== VisitStatus::InService) {
                throw new InvalidVisitTransition($current, $current->status, VisitStatus::Waiting);
            }

            $destination = Department::findOrFail($to->getKey());

            if ($destination->facility_id !== $current->facility_id) {
                throw InvalidDepartmentTransfer::otherFacility();
            }

            if ($destination->id === $current->department_id) {
                throw InvalidDepartmentTransfer::alreadyThere($current, $destination);
            }

            if (! $destination->is_active) {
                throw InvalidDepartmentTransfer::inactive($destination);
            }

            if ($destination->requires_doctor_assignment) {
                if ($doctor === null) {
                    throw InvalidDepartmentTransfer::needsDoctor($destination);
                }

                $isAvailable = User::query()
                    ->availableDoctors($destination->facility_id, $destination->id)
                    ->whereKey($doctor->id)
                    ->exists();

                if (! $isAvailable) {
                    throw InvalidDepartmentTransfer::doctorNotAvailable($destination);
                }
            }

            $current->update([
                'department_id' => $destination->id,
                'department_queue_number' => $destination->requires_doctor_assignment ? null : $this->queueNumbers->next($current->facility_id, $destination->id),
                'assigned_doctor_id' => $destination->requires_doctor_assignment ? $doctor->id : $current->assigned_doctor_id,
                'doctor_queue_number' => $destination->requires_doctor_assignment ? $this->queueNumbers->next($current->facility_id, $destination->id, $doctor->id) : null,
                'status' => VisitStatus::Waiting,
                'department_entered_at' => now(),
                // A new department is a new queue, so they can be told they're almost up there too.
                'almost_turn_notified' => false,
            ]);

            VisitEvent::create([
                'visit_id' => $current->id,
                'department_id' => $destination->id,
                'event' => VisitEventType::Transferred,
                'user_id' => $actor->id,
            ]);

            if ($destination->requires_doctor_assignment) {
                VisitEvent::create([
                    'visit_id' => $current->id,
                    'department_id' => $destination->id,
                    'event' => VisitEventType::DoctorAssigned,
                    'user_id' => $actor->id,
                    'meta' => ['doctor_id' => $doctor->id],
                ]);
            }

            VisitTransferred::dispatch($current);

            return $current;
        }, attempts: 3);
    }
}
