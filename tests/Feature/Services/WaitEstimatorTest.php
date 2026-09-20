<?php

namespace Tests\Feature\Services;

use App\Enums\DepartmentType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\DepartmentWaitEstimate;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\WaitEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitEstimatorTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $consultation;

    private Department $laboratory;

    protected function setUp(): void
    {
        parent::setUp();

        // Midday, so "minutes ago" in these tests never crosses the clinic's midnight.
        $this->travelTo(now(config('careflow.timezone'))->setTime(12, 0));

        $this->facility = Facility::factory()->create();
        $this->consultation = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Laboratory]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function visit(int $number, ?Department $department = null, VisitStatus $status = VisitStatus::Waiting, ?int $arrivedMinutesAgo = null, array $attributes = []): Visit
    {
        return Visit::factory()->for($this->facility)->create([
            'department_id' => ($department ?? $this->consultation)->id,
            'patient_id' => Patient::factory()->for($this->facility)->create()->id,
            'queue_number' => $number,
            'status' => $status,
            'department_entered_at' => now()->subMinutes($arrivedMinutesAgo ?? (100 - $number)),
            ...$attributes,
        ]);
    }

    private function ahead(Visit $visit): int
    {
        return app(WaitEstimator::class)->patientsAhead($visit);
    }

    private function estimate(Visit $visit, int $ahead): string
    {
        return app(WaitEstimator::class)->estimate($visit, $ahead)->label();
    }

    public function test_patients_ahead_counts_those_waiting_who_arrived_earlier(): void
    {
        $this->visit(1);
        $this->visit(2);
        $mine = $this->visit(3);
        $this->visit(4);

        $this->assertSame(2, $this->ahead($mine));
    }

    public function test_the_first_in_line_has_nobody_ahead(): void
    {
        $first = $this->visit(1);
        $this->visit(2);

        $this->assertSame(0, $this->ahead($first));
    }

    public function test_someone_already_called_or_being_seen_is_no_longer_ahead_in_the_line(): void
    {
        $this->visit(1, status: VisitStatus::Called);
        $this->visit(2, status: VisitStatus::InService);
        $this->visit(3, status: VisitStatus::Completed);
        $this->visit(4, status: VisitStatus::Cancelled);
        $this->visit(5);
        $mine = $this->visit(6);

        $this->assertSame(1, $this->ahead($mine));
    }

    public function test_only_this_departments_line_counts(): void
    {
        $this->visit(1, $this->laboratory);
        $this->visit(2);
        $mine = $this->visit(3);
        $otherFacility = Facility::factory()->create();
        Visit::factory()->for($otherFacility)->create([
            'department_id' => Department::factory()->for($otherFacility)->create()->id,
            'queue_number' => 4,
            'department_entered_at' => now()->subMinutes(200),
        ]);

        $this->assertSame(1, $this->ahead($mine));
    }

    public function test_yesterdays_leftovers_do_not_count(): void
    {
        $this->visit(1, attributes: ['created_at' => now()->subDays(2), 'department_entered_at' => now()->subDays(2)]);
        $mine = $this->visit(2);

        $this->assertSame(0, $this->ahead($mine));
    }

    public function test_the_line_is_by_arrival_at_the_department_not_by_registration_number(): void
    {
        $first = $this->visit(40, arrivedMinutesAgo: 30);
        $sentOn = $this->visit(3, arrivedMinutesAgo: 1, attributes: ['department_queue_number' => 7]);

        $this->assertSame(1, $this->ahead($sentOn), 'Registered early but arrived last: they are behind number 40.');
        $this->assertSame(0, $this->ahead($first));
    }

    public function test_arriving_at_the_same_moment_falls_back_to_registration_number_then_id(): void
    {
        $arrival = now()->subMinutes(10);
        $five = $this->visit(5, attributes: ['department_entered_at' => $arrival]);
        $six = $this->visit(6, attributes: ['department_entered_at' => $arrival]);
        $sixToo = $this->visit(6, attributes: ['department_entered_at' => $arrival]);
        $seven = $this->visit(7, attributes: ['department_entered_at' => $arrival]);

        $this->assertSame([0, 1, 2, 3], [$this->ahead($five), $this->ahead($six), $this->ahead($sixToo), $this->ahead($seven)]);
    }

    public function test_a_visit_that_is_not_waiting_has_nobody_ahead(): void
    {
        $this->visit(1);
        $called = $this->visit(2, status: VisitStatus::Called);

        $this->assertSame(0, $this->ahead($called));
    }

    public function test_a_visit_with_no_department_has_nobody_ahead(): void
    {
        $this->visit(1);
        $visit = $this->visit(2);
        $visit->department_id = null;

        $this->assertSame(0, $this->ahead($visit));
    }

    public function test_without_history_the_estimate_uses_the_default_pace_as_a_range(): void
    {
        config(['careflow.tracking.default_minutes_per_patient' => 5]);

        $this->assertSame('25–35 minutes', $this->estimate($this->visit(7), 6));
    }

    public function test_nobody_ahead_reads_as_a_few_minutes_at_most(): void
    {
        $this->assertSame('5 minutes or less', $this->estimate($this->visit(1), 0));
    }

    public function test_the_range_is_never_a_single_number(): void
    {
        config(['careflow.tracking.default_minutes_per_patient' => 5]);
        $estimate = app(WaitEstimator::class)->estimate($this->visit(1), 2);

        $this->assertGreaterThan($estimate->lowMinutes, $estimate->highMinutes);
    }

    public function test_the_estimate_follows_what_the_nightly_job_measured_for_the_department(): void
    {
        DepartmentWaitEstimate::factory()->create(['department_id' => $this->consultation->id, 'avg_minutes' => 10, 'sample_size' => 40]);

        // Ten minutes a patient, three ahead.
        $this->assertSame('25–35 minutes', $this->estimate($this->visit(4), 3));
    }

    public function test_a_newly_measured_average_is_used_from_the_next_look_with_no_other_change(): void
    {
        config(['careflow.tracking.default_minutes_per_patient' => 5]);
        $visit = $this->visit(4);
        $this->assertSame('10–20 minutes', $this->estimate($visit, 3));

        DepartmentWaitEstimate::factory()->create(['department_id' => $this->consultation->id, 'avg_minutes' => 10, 'sample_size' => 40]);

        $this->assertSame('25–35 minutes', $this->estimate($visit, 3));
    }

    public function test_too_few_measured_patients_falls_back_to_the_default(): void
    {
        config(['careflow.tracking.default_minutes_per_patient' => 5]);
        DepartmentWaitEstimate::factory()->create(['department_id' => $this->consultation->id, 'avg_minutes' => 30, 'sample_size' => 2]);

        $this->assertSame('25–35 minutes', $this->estimate($this->visit(7), 6));
    }

    public function test_another_departments_average_is_not_used(): void
    {
        config(['careflow.tracking.default_minutes_per_patient' => 5]);
        DepartmentWaitEstimate::factory()->create(['department_id' => $this->laboratory->id, 'avg_minutes' => 30, 'sample_size' => 50]);

        $this->assertSame('25–35 minutes', $this->estimate($this->visit(7), 6));
    }

    public function test_more_staff_in_the_department_shorten_the_estimate(): void
    {
        config(['careflow.tracking.default_minutes_per_patient' => 10]);
        User::factory()->for($this->facility)->count(2)->doctor()->create(['department_id' => $this->consultation->id]);

        // Four ahead at 10 minutes each, shared between two doctors.
        $this->assertSame('15–25 minutes', $this->estimate($this->visit(5), 4));
    }

    public function test_a_suspended_staff_member_is_not_counted_as_serving(): void
    {
        config(['careflow.tracking.default_minutes_per_patient' => 10]);
        User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]);
        User::factory()->for($this->facility)->doctor()->suspended()->create(['department_id' => $this->consultation->id]);

        $this->assertSame('30–50 minutes', $this->estimate($this->visit(5), 4));
    }
}
