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
     * The statuses that hold a place in a line ahead of someone. A patient
     * accepted from home holds theirs before they are in the building, so
     * they count: they will be called in their turn unless staff move them.
     *
     * @var list<VisitStatus>
     */
    private const IN_LINE = [VisitStatus::Waiting, VisitStatus::AwaitingArrival];

    /**
     * How many people hold a place in this visit's line ahead of it, in the same
     * order the "almost your turn" text uses (arrival at the department, then
     * registration number). The line is the department's shared one, or, for a
     * patient assigned to a doctor, that doctor's own: a patient never waits
     * behind someone else's doctor's queue. Only people still waiting count:
     * someone already called or being seen is no longer in the line. Nothing
     * is ahead of a patient who isn't waiting themselves.
     */
    public function patientsAhead(Visit $visit): int
    {
        if (! in_array($visit->status, self::IN_LINE, true) || $visit->department_id === null) {
            return 0;
        }

        $arrival = 'coalesce(department_entered_at, created_at)';
        $joined = $visit->joinedQueueAt()->toDateTimeString();

        return Visit::query()
            ->where('facility_id', $visit->facility_id)
            ->where('department_id', $visit->department_id)
            ->whereIn('status', self::IN_LINE)
            ->registeredToday()
            ->when(
                $visit->isInDoctorQueue(),
                fn (Builder $line) => $line->where('assigned_doctor_id', $visit->assigned_doctor_id)->whereNotNull('doctor_queue_number'),
                fn (Builder $line) => $line->whereNull('doctor_queue_number'),
            )
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
        // A patient in one doctor's own line is served by that one doctor, however many the department has.
        $servers = $visit->isInDoctorQueue() ? 1 : $this->servers($visit->department_id);

        return $this->estimateFor($visit->department_id, $patientsAhead, $servers);
    }

    /**
     * The same range for anyone joining a department's line with this many
     * people ahead of them, before they have a visit: what receptionists are
     * shown beside each doctor.
     */
    public function estimateFor(?int $departmentId, int $patientsAhead, int $servers = 1): WaitEstimate
    {
        $best = $patientsAhead * $this->minutesPerPatient($departmentId) / max(1, $servers);

        $low = $this->roundToStep($best * (1 - self::SPREAD));
        $high = max($this->roundToStep($best * (1 + self::SPREAD)), $low + self::ROUND_TO_MINUTES);

        return new WaitEstimate($low, $high);
    }

    /**
     * The wait for someone about to join a department's shared line today: what
     * is in it already, shared out across the staff who serve it. For the
     * facility's public card and for a request accepted into a department that
     * has no doctor lines.
     */
    public function estimateForDepartmentQueue(int $departmentId): WaitEstimate
    {
        $inLine = Visit::query()
            ->where('department_id', $departmentId)
            ->whereIn('status', [...self::IN_LINE, VisitStatus::Called, VisitStatus::InService])
            ->whereNull('doctor_queue_number')
            ->registeredToday()
            ->count();

        return $this->estimateFor($departmentId, $inLine, $this->servers($departmentId));
    }

    /**
     * How long one patient takes at a department: what the nightly job
     * measured, or the configured default until it has enough history.
     */
    public function minutesPerPatient(?int $departmentId): float
    {
        return $this->learnedMinutesPerPatient($departmentId) ?? (float) config('careflow.tracking.default_minutes_per_patient');
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
