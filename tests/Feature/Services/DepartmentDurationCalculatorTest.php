<?php

namespace Tests\Feature\Services;

use App\Enums\DepartmentType;
use App\Enums\VisitEventType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Visit;
use App\Services\DepartmentDurationCalculator;
use App\Support\DepartmentTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\LogsVisitHistory;
use Tests\TestCase;

class DepartmentDurationCalculatorTest extends TestCase
{
    use LogsVisitHistory;
    use RefreshDatabase;

    private Facility $facility;

    private Department $reception;

    private Department $consultation;

    private Department $laboratory;

    private Department $pharmacy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now(config('careflow.timezone'))->setTime(12, 0));

        $this->facility = Facility::factory()->create();
        $this->reception = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Reception]);
        $this->consultation = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Laboratory]);
        $this->pharmacy = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Pharmacy]);
    }

    /**
     * @param  list<DepartmentTime>  $times
     * @return list<array{int, float}>
     */
    private function asPairs(array $times): array
    {
        return array_map(fn (DepartmentTime $time) => [$time->departmentId, round($time->minutes, 2)], $times);
    }

    /**
     * Reception, then consultation, then the laboratory, done at 60 minutes.
     */
    private function threeDepartmentVisit(): Visit
    {
        return $this->visitWith([
            [0, VisitEventType::Registered, $this->reception],
            [5, VisitEventType::Called, $this->reception],
            [6, VisitEventType::Started, $this->reception],
            [12, VisitEventType::Transferred, $this->consultation],
            [20, VisitEventType::Called, $this->consultation],
            [25, VisitEventType::Started, $this->consultation],
            [45, VisitEventType::Transferred, $this->laboratory],
            [50, VisitEventType::Called, $this->laboratory],
            [52, VisitEventType::Started, $this->laboratory],
            [60, VisitEventType::Completed, $this->laboratory],
        ]);
    }

    public function test_a_visit_through_one_department_is_one_stay_from_registering_to_completing(): void
    {
        $visit = $this->visitWith([
            [0, VisitEventType::Registered, $this->consultation],
            [8, VisitEventType::Called, $this->consultation],
            [9, VisitEventType::Started, $this->consultation],
            [24, VisitEventType::Completed, $this->consultation],
        ]);

        $this->assertSame([[$this->consultation->id, 24.0]], $this->asPairs(app(DepartmentDurationCalculator::class)->legsFor($visit)));
    }

    public function test_time_is_given_to_the_right_department_across_three_departments(): void
    {
        $legs = app(DepartmentDurationCalculator::class)->legsFor($this->threeDepartmentVisit());

        $this->assertSame([
            [$this->reception->id, 12.0],
            [$this->consultation->id, 33.0],
            [$this->laboratory->id, 15.0],
        ], $this->asPairs($legs));
    }

    public function test_the_legs_of_a_visit_add_up_to_its_whole_time_in_the_building(): void
    {
        $legs = app(DepartmentDurationCalculator::class)->legsFor($this->threeDepartmentVisit());

        $this->assertSame(60.0, round(array_sum(array_map(fn (DepartmentTime $leg) => $leg->minutes, $legs)), 2));
    }

    public function test_coming_back_to_a_department_is_a_separate_stay(): void
    {
        $visit = $this->visitWith([
            [0, VisitEventType::Registered, $this->consultation],
            [10, VisitEventType::Transferred, $this->laboratory],
            [20, VisitEventType::Transferred, $this->consultation],
            [35, VisitEventType::Completed, $this->consultation],
        ]);

        $this->assertSame([
            [$this->consultation->id, 10.0],
            [$this->laboratory->id, 10.0],
            [$this->consultation->id, 15.0],
        ], $this->asPairs(app(DepartmentDurationCalculator::class)->legsFor($visit)));
    }

    public function test_the_stay_a_patient_is_still_in_has_not_ended_so_is_not_counted(): void
    {
        $visit = $this->visitWith([
            [0, VisitEventType::Registered, $this->reception],
            [12, VisitEventType::Transferred, $this->consultation],
            [20, VisitEventType::Called, $this->consultation],
        ]);

        $this->assertSame([[$this->reception->id, 12.0]], $this->asPairs(app(DepartmentDurationCalculator::class)->legsFor($visit)));
    }

    public function test_a_stay_that_ended_in_cancellation_is_not_counted_but_earlier_ones_are(): void
    {
        $visit = $this->visitWith([
            [0, VisitEventType::Registered, $this->reception],
            [12, VisitEventType::Transferred, $this->consultation],
            [30, VisitEventType::Cancelled, $this->consultation],
        ]);

        $this->assertSame([[$this->reception->id, 12.0]], $this->asPairs(app(DepartmentDurationCalculator::class)->legsFor($visit)));
    }

    public function test_being_called_recalled_or_started_does_not_split_a_stay(): void
    {
        $visit = $this->visitWith([
            [0, VisitEventType::Registered, $this->consultation],
            [5, VisitEventType::Called, $this->consultation],
            [8, VisitEventType::Recalled, $this->consultation],
            [12, VisitEventType::Called, $this->consultation],
            [13, VisitEventType::Started, $this->consultation],
            [30, VisitEventType::Completed, $this->consultation],
        ]);

        $this->assertSame([[$this->consultation->id, 30.0]], $this->asPairs(app(DepartmentDurationCalculator::class)->legsFor($visit)));
    }

    public function test_events_logged_in_the_same_second_are_read_in_the_order_they_happened(): void
    {
        $visit = $this->visitWith([
            [0, VisitEventType::Registered, $this->reception],
            [10, VisitEventType::Transferred, $this->consultation],
            [10, VisitEventType::Transferred, $this->laboratory],
            [10, VisitEventType::Completed, $this->laboratory],
        ]);

        $this->assertSame([
            [$this->reception->id, 10.0],
            [$this->consultation->id, 0.0],
            [$this->laboratory->id, 0.0],
        ], $this->asPairs(app(DepartmentDurationCalculator::class)->legsFor($visit)));
    }

    public function test_a_visit_with_no_log_has_no_legs(): void
    {
        $this->assertSame([], app(DepartmentDurationCalculator::class)->legsFor($this->visitWith([])));
    }

    public function test_service_time_is_from_started_to_being_sent_on_or_finished_in_each_department(): void
    {
        $service = app(DepartmentDurationCalculator::class)->serviceFor($this->threeDepartmentVisit());

        $this->assertSame([
            [$this->reception->id, 6.0],
            [$this->consultation->id, 20.0],
            [$this->laboratory->id, 8.0],
        ], $this->asPairs($service));
    }

    public function test_service_that_was_interrupted_by_a_recall_or_a_cancellation_is_not_counted(): void
    {
        $recalled = $this->visitWith([
            [0, VisitEventType::Registered, $this->consultation],
            [5, VisitEventType::Started, $this->consultation],
            [6, VisitEventType::Recalled, $this->consultation],
            [30, VisitEventType::Called, $this->consultation],
            [31, VisitEventType::Started, $this->consultation],
            [41, VisitEventType::Completed, $this->consultation],
        ]);
        $cancelled = $this->visitWith([
            [0, VisitEventType::Registered, $this->consultation],
            [5, VisitEventType::Started, $this->consultation],
            [50, VisitEventType::Cancelled, $this->consultation],
        ]);
        $calculator = app(DepartmentDurationCalculator::class);

        $this->assertSame([[$this->consultation->id, 10.0]], $this->asPairs($calculator->serviceFor($recalled)), 'Only the second, whole service counts.');
        $this->assertSame([], $calculator->serviceFor($cancelled));
    }

    public function test_a_pin_reset_during_service_neither_splits_a_stay_nor_cancels_the_service(): void
    {
        $visit = $this->visitWith([
            [0, VisitEventType::Registered, $this->consultation],
            [5, VisitEventType::Started, $this->consultation],
            [10, VisitEventType::PinReset, $this->consultation],
            [25, VisitEventType::Completed, $this->consultation],
        ]);
        $calculator = app(DepartmentDurationCalculator::class);

        $this->assertSame([[$this->consultation->id, 25.0]], $this->asPairs($calculator->legsFor($visit)));
        $this->assertSame([[$this->consultation->id, 20.0]], $this->asPairs($calculator->serviceFor($visit)));
    }

    public function test_the_stays_of_many_visits_are_read_together(): void
    {
        $first = $this->threeDepartmentVisit();
        $second = $this->visitWith([
            [0, VisitEventType::Registered, $this->pharmacy],
            [7, VisitEventType::Completed, $this->pharmacy],
        ]);
        $calculator = app(DepartmentDurationCalculator::class);

        $legs = $calculator->legsOfVisits(Visit::whereKey([$first->id, $second->id]));

        $this->assertSame(
            array_merge($this->asPairs($calculator->legsFor($first)), $this->asPairs($calculator->legsFor($second))),
            $this->asPairs($legs->all()),
        );
        $this->assertCount(4, $legs);
        $this->assertCount(3, $calculator->serviceOfVisits(Visit::whereKey([$first->id]))->merge($calculator->serviceOfVisits(Visit::whereKey([$second->id]))));
    }

    public function test_a_query_that_selects_no_visits_gives_nothing(): void
    {
        $this->assertCount(0, app(DepartmentDurationCalculator::class)->legsOfVisits(Visit::whereKey(999999)));
    }

    public function test_the_hours_a_patient_accepted_from_home_spent_at_home_are_not_time_in_the_department(): void
    {
        $visit = $this->visitWith([
            [0, VisitEventType::Registered, $this->consultation],
            [90, VisitEventType::CheckedIn, $this->consultation],
            [95, VisitEventType::Called, $this->consultation],
            [96, VisitEventType::Started, $this->consultation],
            [120, VisitEventType::Completed, $this->consultation],
        ]);
        $calculator = app(DepartmentDurationCalculator::class);

        $this->assertSame([[$this->consultation->id, 30.0]], $this->asPairs($calculator->legsFor($visit)), 'Their stay starts when they arrive, not when they were given a place.');
        $this->assertSame([[$this->consultation->id, 24.0]], $this->asPairs($calculator->serviceFor($visit)), 'Being seen is unaffected.');
    }

    public function test_a_self_check_in_is_unchanged_because_it_arrives_the_moment_it_registers(): void
    {
        $visit = $this->visitWith([
            [0, VisitEventType::Registered, $this->consultation],
            [0, VisitEventType::CheckedIn, $this->consultation],
            [5, VisitEventType::Called, $this->consultation],
            [6, VisitEventType::Started, $this->consultation],
            [20, VisitEventType::Completed, $this->consultation],
        ]);

        $this->assertSame([[$this->consultation->id, 20.0]], $this->asPairs(app(DepartmentDurationCalculator::class)->legsFor($visit)));
    }

    public function test_a_check_in_with_no_stay_to_anchor_to_is_ignored(): void
    {
        $visit = $this->visitWith([[5, VisitEventType::CheckedIn, $this->consultation]]);

        $this->assertSame([], app(DepartmentDurationCalculator::class)->legsFor($visit));
    }

    public function test_arriving_signalling_or_being_moved_back_mid_service_does_not_cancel_the_service(): void
    {
        $visit = $this->visitWith([
            [0, VisitEventType::Registered, $this->consultation],
            [5, VisitEventType::Started, $this->consultation],
            [8, VisitEventType::ArrivalSignaled, $this->consultation],
            [9, VisitEventType::CheckedIn, $this->consultation],
            [10, VisitEventType::Skipped, $this->consultation],
            [25, VisitEventType::Completed, $this->consultation],
        ]);

        $this->assertSame([[$this->consultation->id, 20.0]], $this->asPairs(app(DepartmentDurationCalculator::class)->serviceFor($visit)));
    }
}
