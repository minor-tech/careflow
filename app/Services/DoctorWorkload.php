<?php

namespace App\Services;

use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use App\Support\DepartmentTime;
use Illuminate\Support\Collection;

/**
 * How busy each doctor has been, for the facility manager: the patients each
 * was assigned, how long those patients waited for them, and how long the
 * doctor spent with them. All of it is read from the visit log, so these are
 * what happened, not projections.
 *
 * It is management information. Nothing here reaches a patient's own page.
 */
class DoctorWorkload
{
    /** How far back the report looks. */
    public const WINDOW_DAYS = 30;

    /** Visits are read in batches this big, so a month of visits isn't one enormous query. */
    private const BATCH = 500;

    public function __construct(private DepartmentDurationCalculator $durations) {}

    /**
     * One row per doctor who had patients in the window, the busiest first.
     *
     * - patients: visits assigned to them (cancelled ones left out: nobody was seen)
     * - wait: from being assigned to them to being called, averaged over those who were called
     * - consultation: from starting with them to finishing or being sent on, averaged over those who got that far
     *
     * A patient handed from one doctor to another counts for the doctor they
     * ended up with, and their wait is counted from that handover, not from
     * registration, because that is when this doctor's line began for them.
     * Either average is null when no patient got that far.
     *
     * @return list<array{doctor_id: int, name: string, patients: int, avg_wait_minutes: int|null, avg_consultation_minutes: int|null}>
     */
    public function lastThirtyDays(int $facilityId): array
    {
        $visits = Visit::query()
            ->where('facility_id', $facilityId)
            ->whereNotNull('assigned_doctor_id')
            ->where('status', '!=', VisitStatus::Cancelled)
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->get(['id', 'assigned_doctor_id']);

        if ($visits->isEmpty()) {
            return [];
        }

        $doctors = User::query()
            ->whereIn('id', $visits->pluck('assigned_doctor_id')->unique())
            ->get(['id', 'name', 'department_id'])
            ->keyBy('id');

        // Merged with + and not flatMap: the visit ids are the keys, and collapsing would renumber them.
        $perVisit = collect();

        foreach ($visits->pluck('assigned_doctor_id', 'id')->chunk(self::BATCH) as $doctorByVisit) {
            $perVisit = $perVisit->union($this->measure($doctorByVisit, $doctors));
        }

        return $visits
            ->groupBy('assigned_doctor_id')
            ->map(function (Collection $doctorVisits, int $doctorId) use ($doctors, $perVisit): array {
                $measured = $perVisit->only($doctorVisits->pluck('id')->all());

                return [
                    'doctor_id' => $doctorId,
                    'name' => $doctors[$doctorId]->doctorName(),
                    'patients' => $doctorVisits->count(),
                    'avg_wait_minutes' => $this->average($measured->pluck('wait')),
                    'avg_consultation_minutes' => $this->average($measured->pluck('consultation')),
                ];
            })
            ->sort(fn (array $a, array $b) => [$b['patients'], $a['name']] <=> [$a['patients'], $b['name']])
            ->values()
            ->all();
    }

    /**
     * The wait and consultation minutes (or null) of each of these visits.
     *
     * @param  Collection<int, int>  $doctorByVisit  Doctor id keyed by visit id.
     * @param  Collection<int, User>  $doctors
     * @return Collection<int, array{wait: float|null, consultation: float|null}>
     */
    private function measure(Collection $doctorByVisit, Collection $doctors): Collection
    {
        return VisitEvent::query()
            ->whereIn('visit_id', $doctorByVisit->keys())
            ->orderBy('id')
            ->get()
            ->groupBy('visit_id')
            ->map(function (Collection $events, int $visitId) use ($doctorByVisit, $doctors): array {
                // The log from the last time the patient was handed to a doctor onwards: the stretch that belongs to that doctor.
                $assignedAt = $events->last(fn (VisitEvent $event) => in_array($event->event, [VisitEventType::DoctorAssigned, VisitEventType::DoctorReassigned], true));

                if ($assignedAt === null) {
                    return ['wait' => null, 'consultation' => null];
                }

                $theirs = $events->filter(fn (VisitEvent $event) => $event->id >= $assignedAt->id)->values();
                $called = $theirs->first(fn (VisitEvent $event) => $event->event === VisitEventType::Called);

                // Someone accepted from home only starts waiting for the doctor when they arrive.
                $waitFrom = $theirs->first(fn (VisitEvent $event) => $event->event === VisitEventType::CheckedIn) ?? $assignedAt;

                // The doctor's own stay: a consultation elsewhere in the building isn't theirs.
                $departmentId = $doctors[$doctorByVisit[$visitId]]->department_id;
                $consultations = collect($this->durations->serviceIn($theirs))
                    ->filter(fn (DepartmentTime $time) => $time->departmentId === $departmentId);

                return [
                    'wait' => $called === null ? null : max(0, $called->created_at->getTimestamp() - $waitFrom->created_at->getTimestamp()) / 60,
                    'consultation' => $consultations->isEmpty() ? null : $consultations->avg('minutes'),
                ];
            });
    }

    /**
     * @param  Collection<int, float|null>  $minutes
     */
    private function average(Collection $minutes): ?int
    {
        $known = $minutes->filter(fn (?float $value) => $value !== null);

        return $known->isEmpty() ? null : (int) round($known->avg());
    }
}
