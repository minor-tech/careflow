<?php

namespace App\Actions;

use App\Enums\DepartmentType;
use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Events\VisitRegistered;
use App\Models\Department;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use App\Services\QueueNumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class RegisterPatientVisit
{
    public function __construct(private QueueNumberGenerator $queueNumbers) {}

    /**
     * Register a patient at the staff member's facility and put them in the
     * queue: find or create the patient, take the next queue number and create
     * the visit, all in one transaction so a failure part-way leaves nothing
     * behind (and doesn't use up a queue number).
     *
     * @param  array{phone: string, name: string, dob?: string|null, gender?: string|null, department_id?: int|string|null}  $data  Validated; phone already canonical.
     */
    public function handle(User $staff, array $data): Visit
    {
        $facilityId = $staff->facility_id;

        // Retried a few times: if MySQL picks this transaction as a deadlock
        // victim (two registrations colliding on the first number of the day)
        // nothing has been committed, so running it again is safe.
        return DB::transaction(function () use ($staff, $facilityId, $data): Visit {
            $patient = $this->findOrCreatePatient($facilityId, $data);

            $queueNumber = $this->queueNumbers->next($facilityId);

            $visit = Visit::create([
                'facility_id' => $facilityId,
                'patient_id' => $patient->id,
                'department_id' => $data['department_id'] ?? $this->receptionDepartmentId($facilityId),
                'queue_number' => $queueNumber,
                'department_entered_at' => now(),
                'status' => VisitStatus::Waiting,
                'created_by' => $staff->id,
            ]);

            // The first entry in the visit's audit log.
            VisitEvent::create([
                'visit_id' => $visit->id,
                'department_id' => $visit->department_id,
                'event' => VisitEventType::Registered,
                'user_id' => $staff->id,
            ]);

            // Reacted to only once this transaction commits, so a registration that rolls back tells no one.
            VisitRegistered::dispatch($visit);

            return $visit;
        }, attempts: 3);
    }

    /**
     * Reuse the facility's patient with this phone number, or create one. For
     * a returning patient the name is brought up to date, and date of birth
     * and gender are only changed when given: leaving them blank never erases
     * what is already on record.
     *
     * @param  array<string, mixed>  $data
     */
    private function findOrCreatePatient(int $facilityId, array $data): Patient
    {
        $existing = fn () => Patient::where('facility_id', $facilityId)->where('phone', $data['phone']);

        $patient = $existing()->first();

        if ($patient === null) {
            try {
                // A nested transaction, i.e. a savepoint, so a lost race below
                // undoes only this insert.
                $patient = DB::transaction(fn () => Patient::create([
                    'facility_id' => $facilityId,
                    'phone' => $data['phone'],
                    'name' => $data['name'],
                    'dob' => $data['dob'] ?? null,
                    'gender' => $data['gender'] ?? null,
                ]));
            } catch (UniqueConstraintViolationException) {
                // Another registration created this patient a moment ago. A
                // plain read may not see them (this transaction's snapshot is
                // older than their commit), but a locking read always does.
                $patient = $existing()->lockForUpdate()->firstOrFail();
            }
        }

        if (! $patient->wasRecentlyCreated) {
            $patient->fill(['name' => $data['name']])
                ->fill(array_filter(
                    ['dob' => $data['dob'] ?? null, 'gender' => $data['gender'] ?? null],
                    fn ($value) => filled($value),
                ))
                ->save();
        }

        return $patient;
    }

    /**
     * Where a visit starts when no department was chosen: the facility's
     * Reception, or nowhere if it has none.
     */
    private function receptionDepartmentId(int $facilityId): ?int
    {
        return Department::where('facility_id', $facilityId)
            ->where('type', DepartmentType::Reception)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');
    }
}
