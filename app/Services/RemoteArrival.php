<?php

namespace App\Services;

use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Exceptions\InvalidArrivalAction;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A patient accepted from home holds a place in the line before they are in
 * the building. This is everything that happens between being accepted and
 * being an ordinary waiting patient: telling staff they've come, staff
 * confirming it, and what to do when they haven't.
 *
 * Every operation works on the locked, re-read visit, so two people pressing
 * buttons at once (or a patient and a receptionist) can't both win.
 */
class RemoteArrival
{
    /**
     * The patient says they have arrived. That is only a claim: it does not put
     * them in the active queue, it only shows staff "says they've arrived" so
     * they can look up and confirm it. Returns whether it was newly recorded
     * (tapping twice, or after being checked in, does nothing).
     */
    public function signal(Visit $visit): bool
    {
        return DB::transaction(function () use ($visit): bool {
            $current = $this->lock($visit);

            if (! $current->isAwaitingArrival() || $current->arrival_signaled_at !== null) {
                return false;
            }

            $current->update(['arrival_signaled_at' => now()]);

            VisitEvent::create([
                'visit_id' => $current->id,
                'department_id' => $current->department_id,
                'event' => VisitEventType::ArrivalSignaled,
            ]);

            return true;
        });
    }

    /**
     * Staff confirm the patient is physically here: the one step that makes
     * them a waiting patient. They keep the place they were given when
     * accepted, and from now on Call, Start and Complete work as for anyone.
     *
     * @throws InvalidArrivalAction
     */
    public function checkIn(Visit $visit, User $actor): Visit
    {
        return DB::transaction(function () use ($visit, $actor): Visit {
            $current = $this->lockAwaiting($visit);

            $current->update([
                'status' => VisitStatus::Waiting,
                'arrived_at' => now(),
                'arrival_grace_started_at' => null,
            ]);

            VisitEvent::create([
                'visit_id' => $current->id,
                'department_id' => $current->department_id,
                'event' => VisitEventType::CheckedIn,
                'user_id' => $actor->id,
            ]);

            return $current;
        });
    }

    /**
     * Staff choose to give a patient who is next but not here a further grace
     * period: the clock starts again from now.
     *
     * @throws InvalidArrivalAction
     */
    public function extendGrace(Visit $visit): Visit
    {
        return DB::transaction(function () use ($visit): Visit {
            $current = $this->lockAwaiting($visit);

            $current->update(['arrival_grace_started_at' => now()]);

            return $current;
        });
    }

    /**
     * Let the next person who is here go first. The patient who isn't here
     * moves to just behind them; nothing is deleted, and if they turn up and
     * check in they simply wait from there, near the back, as any late arrival
     * would. Their grace period starts afresh when they are next in line.
     *
     * @throws InvalidArrivalAction when nobody who is here is behind them
     */
    public function skip(Visit $visit, User $actor): Visit
    {
        return DB::transaction(function () use ($visit, $actor): Visit {
            $current = $this->lockAwaiting($visit);

            $next = $this->nextWaitingBehind($current) ?? throw InvalidArrivalAction::nobodyToMoveBehind();

            $current->update([
                'department_entered_at' => $next->joinedQueueAt()->copy()->addSecond(),
                'arrival_grace_started_at' => null,
            ]);

            VisitEvent::create([
                'visit_id' => $current->id,
                'department_id' => $current->department_id,
                'event' => VisitEventType::Skipped,
                'user_id' => $actor->id,
            ]);

            return $current;
        });
    }

    /**
     * The first patient who is here and waiting after this one in the same line,
     * in the order the line is worked.
     */
    private function nextWaitingBehind(Visit $visit): ?Visit
    {
        $arrival = 'coalesce(department_entered_at, created_at)';
        $joined = $visit->joinedQueueAt()->toDateTimeString();

        return Visit::query()
            ->where('facility_id', $visit->facility_id)
            ->where('department_id', $visit->department_id)
            ->where('status', VisitStatus::Waiting)
            ->registeredToday()
            ->when(
                $visit->isInDoctorQueue(),
                fn (Builder $line) => $line->where('assigned_doctor_id', $visit->assigned_doctor_id)->whereNotNull('doctor_queue_number'),
                fn (Builder $line) => $line->whereNull('doctor_queue_number'),
            )
            ->where(function (Builder $after) use ($arrival, $joined, $visit): void {
                $after->whereRaw("{$arrival} > ?", [$joined])
                    ->orWhere(function (Builder $tied) use ($arrival, $joined, $visit): void {
                        $tied->whereRaw("{$arrival} = ?", [$joined])
                            ->where(function (Builder $order) use ($visit): void {
                                $order->where('queue_number', '>', $visit->queue_number)
                                    ->orWhere(fn (Builder $same) => $same->where('queue_number', $visit->queue_number)->where('id', '>', $visit->id));
                            });
                    });
            })
            ->orderByRaw($arrival)
            ->orderBy('queue_number')
            ->orderBy('id')
            ->first();
    }

    private function lock(Visit $visit): Visit
    {
        return Visit::whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @throws InvalidArrivalAction
     */
    private function lockAwaiting(Visit $visit): Visit
    {
        $current = $this->lock($visit);

        if (! $current->isAwaitingArrival()) {
            throw InvalidArrivalAction::notAwaitingArrival();
        }

        return $current;
    }
}
