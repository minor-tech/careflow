<?php

namespace App\Actions;

use App\Enums\DepartmentType;
use App\Enums\VisitEventType;
use App\Enums\VisitSource;
use App\Enums\VisitStatus;
use App\Events\VisitRegistered;
use App\Models\Department;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use App\Services\QueueNumberGenerator;
use App\Support\AccessPin;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class RegisterPatientVisit
{
    public function __construct(private QueueNumberGenerator $queueNumbers) {}

    /**
     * Register a patient at the staff member's facility and put them in the
     * queue: find or create the patient, take the next queue number and create
     * the visit, all in one transaction so a failure part-way leaves nothing
     * behind (and doesn't use up a queue number).
     *
     * Other ways in use the same registration: a request accepted from home
     * (awaiting arrival: they hold their place in the line but are not in the
     * building yet) and a patient who checked themselves in on their own phone
     * (already here, so waiting at once). $announce is false where the caller
     * sends its own, more fitting, message than the registration text.
     *
     * The visit keeps only a hash of its access PIN. The caller passes the PIN
     * in so it can show it once; without one, a PIN is made and thrown away,
     * leaving a visit that can be followed by its link only.
     *
     * Where the department gives each patient their own doctor, the visit is
     * also assigned to the doctor in $data and joins that doctor's personal
     * queue, with its own number and an audit entry saying who it was assigned
     * to. The doctor was chosen by the receptionist and checked by the request:
     * this never picks one. A department that needs a doctor refuses to
     * register anyone without one, so no patient can land in a line nobody sees.
     *
     * @param  array{phone: string, name: string, dob?: string|null, gender?: string|null, department_id?: int|string|null, service_id?: int|string|null, doctor_id?: int|string|null}  $data  Validated; phone already canonical.
     *
     * @throws InvalidArgumentException when the department needs a doctor and none was given
     */
    public function handle(
        User $staff,
        array $data,
        ?string $accessPin = null,
        VisitSource $source = VisitSource::WalkIn,
        bool $awaitingArrival = false,
        bool $announce = true,
    ): Visit {
        $facilityId = $staff->facility_id;

        // Hashed before the transaction opens: a deliberately slow hash must not
        // run while the facility's queue counter is locked.
        $accessPinHash = Hash::make($accessPin ?? AccessPin::generate());

        // Retried a few times: if MySQL picks this transaction as a deadlock
        // victim (two registrations colliding on the first number of the day)
        // nothing has been committed, so running it again is safe.
        return DB::transaction(function () use ($staff, $facilityId, $data, $accessPinHash, $source, $awaitingArrival, $announce): Visit {
            $patient = $this->findOrCreatePatient($facilityId, $data);

            $queueNumber = $this->queueNumbers->next($facilityId);

            $departmentId = $data['department_id'] ?? $this->receptionDepartmentId($facilityId);
            $doctorId = $this->doctorIdFor($departmentId, $data['doctor_id'] ?? null);

            $visit = Visit::create([
                'facility_id' => $facilityId,
                'patient_id' => $patient->id,
                'department_id' => $departmentId,
                'assigned_doctor_id' => $doctorId,
                'doctor_queue_number' => $doctorId === null ? null : $this->queueNumbers->next($facilityId, $departmentId, $doctorId),
                'service_id' => $data['service_id'] ?? null,
                'queue_number' => $queueNumber,
                'department_entered_at' => now(),
                'status' => $awaitingArrival ? VisitStatus::AwaitingArrival : VisitStatus::Waiting,
                'source' => $source,
                // Someone who checked themselves in is confirmed by staff standing in front of them: they are here now.
                'arrived_at' => $source === VisitSource::SelfCheckin ? now() : null,
                'created_by' => $staff->id,
                'access_pin_hash' => $accessPinHash,
            ]);

            // The first entry in the visit's audit log.
            VisitEvent::create([
                'visit_id' => $visit->id,
                'department_id' => $visit->department_id,
                'event' => VisitEventType::Registered,
                'user_id' => $staff->id,
            ]);

            if ($doctorId !== null) {
                VisitEvent::create([
                    'visit_id' => $visit->id,
                    'department_id' => $visit->department_id,
                    'event' => VisitEventType::DoctorAssigned,
                    'user_id' => $staff->id,
                    'meta' => ['doctor_id' => $doctorId],
                ]);
            }

            if ($source === VisitSource::SelfCheckin) {
                VisitEvent::create([
                    'visit_id' => $visit->id,
                    'department_id' => $visit->department_id,
                    'event' => VisitEventType::CheckedIn,
                    'user_id' => $staff->id,
                ]);
            }

            // Reacted to only once this transaction commits, so a registration that rolls back tells no one.
            if ($announce) {
                VisitRegistered::dispatch($visit);
            }

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
     * The doctor the visit is assigned to: the one given, when the department
     * assigns patients to doctors, and nobody where it doesn't.
     */
    private function doctorIdFor(?int $departmentId, int|string|null $doctorId): ?int
    {
        $requiresDoctor = $departmentId !== null
            && Department::whereKey($departmentId)->value('requires_doctor_assignment');

        if (! $requiresDoctor) {
            return null;
        }

        return $doctorId === null
            ? throw new InvalidArgumentException('This department assigns patients to a doctor: choose one before registering.')
            : (int) $doctorId;
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
