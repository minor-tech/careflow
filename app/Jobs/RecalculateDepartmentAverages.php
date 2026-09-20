<?php

namespace App\Jobs;

use App\Models\Department;
use App\Models\DepartmentWaitEstimate;
use App\Models\Facility;
use App\Models\Visit;
use App\Services\DepartmentDurationCalculator;
use App\Support\DepartmentTime;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Collection;

/**
 * The nightly batch that turns the visit log into what WaitEstimator uses: for
 * every department, the average time one patient takes to be served, over the
 * last 30 days. Run by the scheduler (see routes/console.php), not per page
 * view.
 *
 * It measures service time (started to finished), not the whole stay:
 * a stay includes queueing, and a wait estimate multiplies this figure by the
 * number of people ahead, so using the stay would count the queueing twice.
 *
 * It deliberately runs where it is called rather than through the queue: a
 * nightly batch that silently waits for a worker that isn't running is worse
 * than one that shows its error in the scheduler's output.
 */
class RecalculateDepartmentAverages
{
    use Dispatchable;

    public const WINDOW_DAYS = 30;

    /**
     * A "service" shorter or longer than this was a mis-click or a forgotten
     * Complete, not a real one, and would drag the average around.
     */
    public const SANE_SERVICE_MINUTES = [1, 120];

    public function handle(DepartmentDurationCalculator $durations): void
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        Facility::query()->orderBy('id')->each(function (Facility $facility) use ($durations, $since): void {
            $service = $durations
                ->serviceOfVisits(Visit::query()->where('facility_id', $facility->id)->where('created_at', '>=', $since))
                ->filter(fn (DepartmentTime $time) => $time->minutes >= self::SANE_SERVICE_MINUTES[0] && $time->minutes <= self::SANE_SERVICE_MINUTES[1])
                ->groupBy('departmentId');

            $facility->departments()->orderBy('id')->each(fn (Department $department) => $this->record($department, $service->get($department->id)));
        });
    }

    /**
     * Save a department's average, or clear it if there is nothing recent to
     * average, so a department that has gone quiet falls back to the default
     * rather than keeping a stale figure forever.
     *
     * @param  Collection<int, DepartmentTime>|null  $times
     */
    private function record(Department $department, ?Collection $times): void
    {
        if ($times === null || $times->isEmpty()) {
            DepartmentWaitEstimate::where('department_id', $department->id)->delete();

            return;
        }

        DepartmentWaitEstimate::updateOrCreate(
            ['department_id' => $department->id],
            [
                'avg_minutes' => round($times->avg('minutes'), 1),
                'sample_size' => $times->count(),
                'calculated_at' => now(),
            ],
        );
    }
}
