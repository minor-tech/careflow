<?php

namespace App\Services;

use App\Enums\VisitEventType;
use App\Models\Visit;
use App\Models\VisitEvent;
use App\Support\DepartmentTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reads how long patients spent at each department out of the visit log.
 *
 * Nothing is stored for this: a visit's events say when it entered each
 * department (registered, then every transfer) and when it left (the next
 * entry, or completed), so the times can be rebuilt on demand.
 *
 * Two different questions are answered, and they are not interchangeable:
 *
 *  - a LEG is a whole stay at a department, queueing included. It answers
 *    "where does a patient's day go?" and drives the bottleneck report.
 *  - SERVICE is only the time being served, from "started" to the end of
 *    that. It answers "how long does one patient take?", which is what
 *    a wait estimate multiplies by the number of people ahead. Using legs for
 *    that would count everyone's queueing over again.
 *
 * Only stays that ended in a hand-over count: a transfer or completion. A
 * cancellation ends a stay too, but it says nothing about how long service
 * really takes, so it is left out.
 */
class DepartmentDurationCalculator
{
    /**
     * Visits are read in batches this big, so a month of visits doesn't mean
     * a query per visit or one query with thousands of parameters.
     */
    private const BATCH = 500;

    /**
     * One visit's stays at each department, in the order they happened.
     *
     * @return list<DepartmentTime>
     */
    public function legsFor(Visit $visit): array
    {
        return $this->legsIn($visit->events()->get());
    }

    /**
     * One visit's time being served at each department.
     *
     * @return list<DepartmentTime>
     */
    public function serviceFor(Visit $visit): array
    {
        return $this->serviceIn($visit->events()->get());
    }

    /**
     * The stays of every visit the query selects, all together.
     *
     * @param  Builder<Visit>  $visits
     * @return Collection<int, DepartmentTime>
     */
    public function legsOfVisits(Builder $visits): Collection
    {
        return $this->acrossVisits($visits, fn (Collection $events) => $this->legsIn($events));
    }

    /**
     * The time being served of every visit the query selects, all together.
     *
     * @param  Builder<Visit>  $visits
     * @return Collection<int, DepartmentTime>
     */
    public function serviceOfVisits(Builder $visits): Collection
    {
        return $this->acrossVisits($visits, fn (Collection $events) => $this->serviceIn($events));
    }

    /**
     * @param  Collection<int, VisitEvent>  $events  One visit's log, oldest first.
     * @return list<DepartmentTime>
     */
    public function legsIn(Collection $events): array
    {
        $legs = [];
        $entered = null; // [department id, when] of the stay in progress

        foreach ($events as $event) {
            switch ($event->event) {
                case VisitEventType::Registered:
                case VisitEventType::Transferred:
                    $this->close($legs, $entered, $event);
                    $entered = $event->department_id === null ? null : [$event->department_id, $event];
                    break;

                case VisitEventType::Completed:
                    $this->close($legs, $entered, $event);
                    $entered = null;
                    break;

                case VisitEventType::Cancelled:
                    $entered = null;
                    break;

                default:
                    // Called, started and recalled happen inside a stay; they don't start or end one.
                    break;
            }
        }

        return $legs;
    }

    /**
     * @param  Collection<int, VisitEvent>  $events  One visit's log, oldest first.
     * @return list<DepartmentTime>
     */
    public function serviceIn(Collection $events): array
    {
        $stints = [];
        $started = null; // [department id, event] of service in progress

        foreach ($events as $event) {
            switch ($event->event) {
                case VisitEventType::Started:
                    $started = $event->department_id === null ? null : [$event->department_id, $event];
                    break;

                case VisitEventType::Completed:
                case VisitEventType::Transferred:
                    $this->close($stints, $started, $event);
                    $started = null;
                    break;

                default:
                    // Sent back to the queue or cancelled part-way: that wasn't a whole service.
                    $started = null;
                    break;
            }
        }

        return $stints;
    }

    /**
     * @param  list<DepartmentTime>  $times
     * @param  array{int, VisitEvent}|null  $since
     */
    private function close(array &$times, ?array $since, VisitEvent $until): void
    {
        if ($since === null) {
            return;
        }

        [$departmentId, $from] = $since;
        $seconds = max(0, $until->created_at->getTimestamp() - $from->created_at->getTimestamp());

        $times[] = new DepartmentTime($departmentId, $seconds / 60);
    }

    /**
     * @param  Builder<Visit>  $visits
     * @param  callable(Collection<int, VisitEvent>): list<DepartmentTime>  $read
     * @return Collection<int, DepartmentTime>
     */
    private function acrossVisits(Builder $visits, callable $read): Collection
    {
        $times = collect();

        foreach ($visits->pluck('id')->chunk(self::BATCH) as $ids) {
            VisitEvent::query()
                ->whereIn('visit_id', $ids)
                ->orderBy('id')
                ->get()
                ->groupBy('visit_id')
                ->each(fn (Collection $events) => $times->push(...$read($events)));
        }

        return $times;
    }
}
