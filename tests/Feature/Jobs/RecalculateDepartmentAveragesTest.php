<?php

namespace Tests\Feature\Jobs;

use App\Enums\DepartmentType;
use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Jobs\RecalculateDepartmentAverages;
use App\Models\Department;
use App\Models\DepartmentWaitEstimate;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\WaitEstimator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\LogsVisitHistory;
use Tests\TestCase;

class RecalculateDepartmentAveragesTest extends TestCase
{
    use LogsVisitHistory;
    use RefreshDatabase;

    private Facility $facility;

    private Department $reception;

    private Department $consultation;

    private Department $laboratory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now(config('careflow.timezone'))->setTime(12, 0));

        $this->facility = Facility::factory()->create();
        $this->reception = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Reception]);
        $this->consultation = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Laboratory]);
    }

    private function runNightlyJob(): void
    {
        RecalculateDepartmentAverages::dispatch();
    }

    /**
     * A visit in which the patient was seen at the department for this many minutes.
     */
    private function served(Department $department, float $minutes, ?Carbon $day = null): Visit
    {
        return $this->visitWith([
            [0, VisitEventType::Registered, $department],
            [5, VisitEventType::Called, $department],
            [6, VisitEventType::Started, $department],
            [6 + $minutes, VisitEventType::Completed, $department],
        ], $day, $department->facility);
    }

    private function estimateOf(Department $department): ?DepartmentWaitEstimate
    {
        return DepartmentWaitEstimate::where('department_id', $department->id)->first();
    }

    public function test_it_records_each_departments_average_service_time_and_how_many_patients_it_is_from(): void
    {
        foreach ([10, 20, 30] as $minutes) {
            $this->served($this->consultation, $minutes);
        }
        foreach ([4, 6] as $minutes) {
            $this->served($this->laboratory, $minutes);
        }

        $this->runNightlyJob();

        $consultation = $this->estimateOf($this->consultation);
        $this->assertSame(20.0, $consultation->avg_minutes);
        $this->assertSame(3, $consultation->sample_size);
        $this->assertSame(5.0, $this->estimateOf($this->laboratory)->avg_minutes);
        $this->assertSame(2, $this->estimateOf($this->laboratory)->sample_size);
        $this->assertEquals(now(), $consultation->calculated_at);
    }

    public function test_it_measures_being_served_not_the_whole_stay_so_queueing_is_not_counted_twice(): void
    {
        // Waited 40 minutes, then was served for 10.
        $this->visitWith([
            [0, VisitEventType::Registered, $this->consultation],
            [40, VisitEventType::Started, $this->consultation],
            [50, VisitEventType::Completed, $this->consultation],
        ]);

        $this->runNightlyJob();

        $this->assertSame(10.0, $this->estimateOf($this->consultation)->avg_minutes);
    }

    public function test_a_patient_who_went_through_several_departments_adds_to_each_of_them(): void
    {
        $this->visitWith([
            [0, VisitEventType::Registered, $this->reception],
            [1, VisitEventType::Started, $this->reception],
            [5, VisitEventType::Transferred, $this->consultation],
            [10, VisitEventType::Started, $this->consultation],
            [30, VisitEventType::Transferred, $this->laboratory],
            [35, VisitEventType::Started, $this->laboratory],
            [43, VisitEventType::Completed, $this->laboratory],
        ]);

        $this->runNightlyJob();

        $this->assertSame([4.0, 20.0, 8.0], [
            $this->estimateOf($this->reception)->avg_minutes,
            $this->estimateOf($this->consultation)->avg_minutes,
            $this->estimateOf($this->laboratory)->avg_minutes,
        ]);
    }

    public function test_only_the_last_30_days_are_used(): void
    {
        $this->served($this->consultation, 10);
        $this->served($this->consultation, 10, now()->subDays(29));
        $this->served($this->consultation, 100, now()->subDays(31));

        $this->runNightlyJob();

        $this->assertSame(2, $this->estimateOf($this->consultation)->sample_size);
        $this->assertSame(10.0, $this->estimateOf($this->consultation)->avg_minutes);
    }

    public function test_a_service_that_was_cancelled_or_sent_back_to_the_queue_is_left_out(): void
    {
        $this->served($this->consultation, 10);
        $this->visitWith([
            [0, VisitEventType::Registered, $this->consultation],
            [5, VisitEventType::Started, $this->consultation],
            [60, VisitEventType::Cancelled, $this->consultation],
        ], attributes: ['status' => VisitStatus::Cancelled]);

        $this->runNightlyJob();

        $this->assertSame(1, $this->estimateOf($this->consultation)->sample_size);
        $this->assertSame(10.0, $this->estimateOf($this->consultation)->avg_minutes);
    }

    public function test_mis_clicks_and_forgotten_completes_do_not_skew_the_average(): void
    {
        foreach ([0.5, 10, 20, 200] as $minutes) {
            $this->served($this->consultation, $minutes);
        }

        $this->runNightlyJob();

        $this->assertSame(15.0, $this->estimateOf($this->consultation)->avg_minutes);
        $this->assertSame(2, $this->estimateOf($this->consultation)->sample_size);
    }

    public function test_each_facilitys_departments_are_averaged_from_their_own_visits(): void
    {
        $otherFacility = Facility::factory()->create();
        $theirs = Department::factory()->for($otherFacility)->create(['type' => DepartmentType::Consultation]);
        $this->served($this->consultation, 10);
        $this->served($theirs, 40);

        $this->runNightlyJob();

        $this->assertSame(10.0, $this->estimateOf($this->consultation)->avg_minutes);
        $this->assertSame(40.0, $this->estimateOf($theirs)->avg_minutes);
    }

    public function test_a_department_with_nothing_measured_gets_no_estimate(): void
    {
        $this->served($this->consultation, 10);

        $this->runNightlyJob();

        $this->assertNull($this->estimateOf($this->laboratory));
        $this->assertNull($this->estimateOf($this->reception));
    }

    public function test_running_it_again_updates_rather_than_duplicates(): void
    {
        $this->served($this->consultation, 10);
        $this->runNightlyJob();
        $this->served($this->consultation, 30);

        $this->runNightlyJob();

        $this->assertSame(1, DepartmentWaitEstimate::where('department_id', $this->consultation->id)->count());
        $this->assertSame(20.0, $this->estimateOf($this->consultation)->avg_minutes);
        $this->assertSame(2, $this->estimateOf($this->consultation)->sample_size);
    }

    public function test_a_department_that_has_gone_quiet_loses_its_stale_estimate(): void
    {
        DepartmentWaitEstimate::factory()->create(['department_id' => $this->laboratory->id, 'avg_minutes' => 99, 'sample_size' => 50]);
        $this->served($this->laboratory, 10, now()->subDays(45));

        $this->runNightlyJob();

        $this->assertNull($this->estimateOf($this->laboratory));
    }

    public function test_it_runs_cleanly_when_there_is_nothing_to_measure(): void
    {
        $this->runNightlyJob();

        $this->assertSame(0, DepartmentWaitEstimate::count());
    }

    public function test_the_wait_estimator_uses_the_new_average_straight_after_a_run(): void
    {
        config(['careflow.tracking.default_minutes_per_patient' => 5]);
        $visit = Visit::factory()->for($this->facility)->create([
            'department_id' => $this->consultation->id,
            'patient_id' => Patient::factory()->for($this->facility)->create()->id,
            'queue_number' => 50,
            'department_entered_at' => now(),
        ]);
        $estimator = app(WaitEstimator::class);
        $this->assertSame('10–20 minutes', $estimator->estimate($visit, 3)->label(), 'Before: the default.');

        foreach ([10, 10, 10] as $minutes) {
            $this->served($this->consultation, $minutes);
        }
        $this->runNightlyJob();

        $this->assertSame('25–35 minutes', $estimator->estimate($visit, 3)->label(), 'After: what this department really takes.');
    }

    public function test_it_is_scheduled_nightly_at_one_in_the_morning_clinic_time(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'recalculate-department-averages');

        $this->assertNotNull($event, 'The nightly job is not on the schedule.');
        $this->assertSame('0 1 * * *', $event->expression);
        $this->assertSame('Africa/Nairobi', (string) $event->timezone);
    }
}
