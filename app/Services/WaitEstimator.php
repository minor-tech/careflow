<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Enums\VisitStatus;
use App\Models\DepartmentWaitEstimate;
use App\Models\User;
use App\Models\Visit;
use App\Support\WaitEstimate;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a waiting patient can be told about their wait: how many people are
 * ahead of them, and a rough guess at how long that takes.
 *
 * The first is a fact the database knows right now. The second is a
 * prediction that one long consultation makes wrong, which is why it is only
 * ever offered as a range, and always second to the count.
 */
class WaitEstimator
{
    /**
     * Fewer measured patients than this and a department's own average isn't
     * trusted yet: the configured default is used instead.
     */
    private const MIN_SAMPLES = 3;

    /**
     * The width of the range around the best guess, and what it rounds to.
     */
    private const SPREAD = 0.2;

    private const ROUND_TO_MINUTES = 5;

    /**
     * How many people are waiting in this visit's department ahead of it, in
     * the same order the "almost your turn" text uses (arrival at the
     * department, then registration number). Only people still waiting count:
     * someone already called or being seen is no longer in the line. Nothing
     * is ahead of a patient who isn't waiting themselves.
     */
    public function patientsAhead(Visit $visit): int
    {
        if ($visit->status !== VisitStatus::Waiting || $visit->department_id === null) {
            return 0;
        }

        $arrival = 'coalesce(department_entered_at, created_at)';
        $joined = $visit->joinedQueueAt()->toDateTimeString();

        return Visit::query()
            ->where('facility_id', $visit->facility_id)
            ->where('department_id', $visit->department_id)
            ->where('status', VisitStatus::Waiting)
            ->registeredToday()
            ->where(function (Builder $ahead) use ($arrival, $joined, $visit): void {
                $ahead->whereRaw("{$arrival} < ?", [$joined])
                    ->orWhere(function (Builder $tied) use ($arrival, $joined, $visit): void {
                        $tied->whereRaw("{$arrival} = ?", [$joined])
                            ->where(function (Builder $order) use ($visit): void {
                                $order->where('queue_number', '<', $visit->queue_number)
                                    ->orWhere(fn (Builder $same) => $same->where('queue_number', $visit->queue_number)->where('id', '<', $visit->id));
                            });
                    });
            })
            ->count();
    }

    /**
     * A range for how long the given number of people ahead will take: the
     * department's usual time per patient (what the nightly job measured, or
     * the default until it has enough history), shared out across the staff
     * who serve it.
     */
    public function estimate(Visit $visit, int $patientsAhead): WaitEstimate
    {
        $perPatient = $this->learnedMinutesPerPatient($visit->department_id) ?? (float) config('careflow.tracking.default_minutes_per_patient');
        $best = $patientsAhead * $perPatient / $this->servers($visit->department_id);

        $low = $this->roundToStep($best * (1 - self::SPREAD));
        $high = max($this->roundToStep($best * (1 + self::SPREAD)), $low + self::ROUND_TO_MINUTES);

        return new WaitEstimate($low, $high);
    }

    /**
     * The time one patient takes at a department, as measured over the last
     * month by RecalculateDepartmentAverages. Null until it has been measured
     * on enough patients. Read from the table the nightly job fills, so a page
     * view never goes digging through the visit log.
     */
    private function learnedMinutesPerPatient(?int $departmentId): ?float
    {
        if ($departmentId === null) {
            return null;
        }

        $learned = DepartmentWaitEstimate::where('department_id', $departmentId)->first();

        return $learned !== null && $learned->sample_size >= self::MIN_SAMPLES ? $learned->avg_minutes : null;
    }

    /**
     * How many people are seeing patients at once. Doctors in a department
     * share one queue, so two of them clear it about twice as fast.
     */
    private function servers(?int $departmentId): int
    {
        if ($departmentId === null) {
            return 1;
        }

        return max(1, User::where('department_id', $departmentId)->where('status', UserStatus::Active)->count());
    }

    private function roundToStep(float $minutes): int
    {
        return (int) (round($minutes / self::ROUND_TO_MINUTES) * self::ROUND_TO_MINUTES);
    }
}
