<?php

namespace App\Services;

use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Visit;
use App\Models\VisitEvent;
use App\Support\DepartmentTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a facility manager sees for today: how many patients came, how long
 * they waited to be called, and which department is taking the longest.
 *
 * "Today" is the clinic's own calendar day (Nairobi), the same day the queue
 * numbers reset on, not the server's. Everything is read from the visit log,
 * so these are what actually happened, not estimates.
 */
class FacilityDailyStats
{
    public function __construct(private DepartmentDurationCalculator $durations) {}

    /**
     * @return array{patients_today: int, completed: int, cancelled: int, in_progress: int, avg_wait_minutes: int|null, longest_wait_minutes: int|null}
     */
    public function summary(int $facilityId): array
    {
        $byStatus = $this->today($facilityId)
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $byStatus->sum();
        $completed = (int) $byStatus->get(VisitStatus::Completed->value, 0);
        $cancelled = (int) $byStatus->get(VisitStatus::Cancelled->value, 0);

        $waits = $this->firstWaits($facilityId);

        return [
            'patients_today' => $total,
            'completed' => $completed,
            'cancelled' => $cancelled,
            // Registered today and neither finished nor cancelled: still somewhere in the building.
            'in_progress' => $total - $completed - $cancelled,
            'avg_wait_minutes' => $waits->isEmpty() ? null : (int) round($waits->avg()),
            'longest_wait_minutes' => $waits->isEmpty() ? null : (int) round($waits->max()),
        ];
    }

    /**
     * How long each department took today, slowest first. A department's time
     * is a patient's whole stay there (queueing included), for every stay that
     * has ended so far today, so the slowest is where patients' time is going.
     *
     * @return list<array{department_id: int, name: string, avg_minutes: int, stays: int, share_of_slowest: float}>
     */
    public function departmentPerformance(int $facilityId): array
    {
        $names = Department::where('facility_id', $facilityId)->pluck('name', 'id');

        $rows = $this->durations
            ->legsOfVisits($this->today($facilityId))
            ->filter(fn (DepartmentTime $leg) => $names->has($leg->departmentId))
            ->groupBy('departmentId')
            ->map(fn (Collection $legs, int $departmentId) => [
                'department_id' => $departmentId,
                'name' => $names[$departmentId],
                'average' => $legs->avg('minutes'),
                'stays' => $legs->count(),
            ])
            ->sort(fn (array $a, array $b) => [$b['average'], $a['name']] <=> [$a['average'], $b['name']])
            ->values();

        $slowest = $rows->max('average') ?: 1;

        return $rows->map(fn (array $row) => [
            'department_id' => $row['department_id'],
            'name' => $row['name'],
            'avg_minutes' => (int) round($row['average']),
            'stays' => $row['stays'],
            'share_of_slowest' => $row['average'] / $slowest,
        ])->all();
    }

    /**
     * @return Builder<Visit>
     */
    private function today(int $facilityId): Builder
    {
        return Visit::query()->where('facility_id', $facilityId)->registeredToday();
    }

    /**
     * For each of today's visits that has been called, the minutes from
     * registering to first being called (a later recall doesn't restart it).
     * Someone still waiting to be called for the first time has no finished
     * wait to report yet, so they aren't in it.
     *
     * @return Collection<int, float>
     */
    private function firstWaits(int $facilityId): Collection
    {
        return VisitEvent::query()
            ->whereIn('visit_id', $this->today($facilityId)->select('id'))
            ->whereIn('event', [VisitEventType::Registered, VisitEventType::Called])
            ->orderBy('id')
            ->get(['visit_id', 'event', 'created_at'])
            ->groupBy('visit_id')
            ->map(function (Collection $events): ?float {
                $registered = $events->first(fn (VisitEvent $event) => $event->event === VisitEventType::Registered);
                $called = $registered === null ? null : $events->first(fn (VisitEvent $event) => $event->event === VisitEventType::Called);

                return $called === null ? null : max(0, $called->created_at->getTimestamp() - $registered->created_at->getTimestamp()) / 60;
            })
            ->filter(fn (?float $minutes) => $minutes !== null)
            ->values();
    }
}
