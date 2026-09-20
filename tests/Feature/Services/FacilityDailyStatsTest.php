<?php

namespace Tests\Feature\Services;

use App\Actions\RegisterPatientVisit;
use App\Enums\DepartmentType;
use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Models\Visit;
use App\Services\FacilityDailyStats;
use App\Services\VisitStatusTransitioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\LogsVisitHistory;
use Tests\TestCase;

class FacilityDailyStatsTest extends TestCase
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
        $this->reception = Department::factory()->for($this->facility)->create(['name' => 'Reception', 'type' => DepartmentType::Reception]);
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
        $this->pharmacy = Department::factory()->for($this->facility)->create(['name' => 'Pharmacy', 'type' => DepartmentType::Pharmacy]);
    }

    private function summary(?Facility $facility = null): array
    {
        return app(FacilityDailyStats::class)->summary(($facility ?? $this->facility)->id);
    }

    /**
     * @return list<array{department_id: int, name: string, avg_minutes: int, stays: int, share_of_slowest: float}>
     */
    private function performance(?Facility $facility = null): array
    {
        return app(FacilityDailyStats::class)->departmentPerformance(($facility ?? $this->facility)->id);
    }

    /**
     * Someone who waited this many minutes to be called first, then was served.
     */
    private function waited(float $minutes, array $attributes = [], ?Facility $facility = null, ?Department $department = null): Visit
    {
        $department ??= $this->consultation;

        return $this->visitWith([
            [0, VisitEventType::Registered, $department],
            [$minutes, VisitEventType::Called, $department],
            [$minutes + 1, VisitEventType::Started, $department],
            [$minutes + 11, VisitEventType::Completed, $department],
        ], facility: $facility, attributes: ['status' => VisitStatus::Completed, ...$attributes]);
    }

    public function test_it_counts_todays_patients_by_outcome_and_those_still_in_progress(): void
    {
        Visit::factory()->for($this->facility)->count(3)->create(['status' => VisitStatus::Completed]);
        Visit::factory()->for($this->facility)->count(2)->create(['status' => VisitStatus::Cancelled]);
        Visit::factory()->for($this->facility)->create(['status' => VisitStatus::Waiting]);
        Visit::factory()->for($this->facility)->create(['status' => VisitStatus::Called]);
        Visit::factory()->for($this->facility)->create(['status' => VisitStatus::InService]);

        $summary = $this->summary();

        $this->assertSame(8, $summary['patients_today']);
        $this->assertSame(3, $summary['completed']);
        $this->assertSame(2, $summary['cancelled']);
        $this->assertSame(3, $summary['in_progress']);
    }

    public function test_it_counts_only_this_facility(): void
    {
        Visit::factory()->for($this->facility)->count(2)->create();
        Visit::factory()->for(Facility::factory())->count(5)->create(['status' => VisitStatus::Completed]);

        $this->assertSame(2, $this->summary()['patients_today']);
        $this->assertSame(0, $this->summary()['completed']);
    }

    public function test_today_is_the_clinics_day_not_the_servers(): void
    {
        $nairobi = config('careflow.timezone');
        // Times are stored in UTC. 00:30 in Nairobi is 21:30 UTC the evening before, and 23:30 in Nairobi is 20:30 UTC the same day.
        Visit::factory()->for($this->facility)->create(['created_at' => now($nairobi)->setTime(0, 30)->utc()]);
        Visit::factory()->for($this->facility)->create(['created_at' => now($nairobi)->setTime(23, 30)->utc()]);
        Visit::factory()->for($this->facility)->create(['created_at' => now($nairobi)->subDay()->setTime(23, 30)->utc()]);
        Visit::factory()->for($this->facility)->create(['created_at' => now($nairobi)->addDay()->setTime(0, 30)->utc()]);

        $this->assertSame(2, $this->summary()['patients_today']);
    }

    public function test_with_no_patients_the_counts_are_zero_and_the_waits_are_empty(): void
    {
        $this->assertSame([
            'patients_today' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'in_progress' => 0,
            'avg_wait_minutes' => null,
            'longest_wait_minutes' => null,
        ], $this->summary());
    }

    public function test_the_wait_is_the_gap_from_registering_to_first_being_called(): void
    {
        $this->waited(10);
        $this->waited(20);
        $this->waited(60);

        $summary = $this->summary();

        $this->assertSame(30, $summary['avg_wait_minutes']);
        $this->assertSame(60, $summary['longest_wait_minutes']);
    }

    public function test_a_recall_does_not_restart_the_wait(): void
    {
        $this->visitWith([
            [0, VisitEventType::Registered, $this->consultation],
            [12, VisitEventType::Called, $this->consultation],
            [15, VisitEventType::Recalled, $this->consultation],
            [50, VisitEventType::Called, $this->consultation],
        ]);

        $this->assertSame(12, $this->summary()['longest_wait_minutes']);
    }

    public function test_someone_not_yet_called_has_no_finished_wait_so_is_not_counted(): void
    {
        $this->waited(10);
        $this->visitWith([[0, VisitEventType::Registered, $this->consultation]], attributes: ['status' => VisitStatus::Waiting]);

        $summary = $this->summary();

        $this->assertSame(10, $summary['avg_wait_minutes']);
        $this->assertSame(2, $summary['patients_today']);
    }

    public function test_a_wait_is_measured_at_the_first_department_not_a_later_transfer(): void
    {
        $this->visitWith([
            [0, VisitEventType::Registered, $this->reception],
            [8, VisitEventType::Called, $this->reception],
            [15, VisitEventType::Transferred, $this->consultation],
            [90, VisitEventType::Called, $this->consultation],
        ]);

        $this->assertSame(8, $this->summary()['longest_wait_minutes']);
    }

    public function test_waits_of_other_facilities_and_other_days_are_not_included(): void
    {
        $this->waited(10);
        $this->waited(200, facility: Facility::factory()->create());
        $this->visitWith([
            [0, VisitEventType::Registered, $this->consultation],
            [300, VisitEventType::Called, $this->consultation],
        ], now()->subDay());

        $summary = $this->summary();

        $this->assertSame(10, $summary['avg_wait_minutes']);
        $this->assertSame(10, $summary['longest_wait_minutes']);
    }

    public function test_an_average_that_hides_one_very_long_wait_is_shown_beside_that_wait(): void
    {
        foreach ([5, 5, 5, 5, 100] as $minutes) {
            $this->waited($minutes);
        }

        $summary = $this->summary();

        $this->assertSame(24, $summary['avg_wait_minutes']);
        $this->assertSame(100, $summary['longest_wait_minutes']);
    }

    public function test_departments_are_listed_slowest_first_with_their_average_and_patient_count(): void
    {
        $this->visitWith([[0, VisitEventType::Registered, $this->reception], [12, VisitEventType::Transferred, $this->consultation], [50, VisitEventType::Completed, $this->consultation]]);
        $this->visitWith([[0, VisitEventType::Registered, $this->reception], [14, VisitEventType::Transferred, $this->consultation], [66, VisitEventType::Completed, $this->consultation]]);
        $this->visitWith([[0, VisitEventType::Registered, $this->pharmacy], [9, VisitEventType::Completed, $this->pharmacy]]);
        $this->visitWith([[0, VisitEventType::Registered, $this->laboratory], [19, VisitEventType::Completed, $this->laboratory]]);

        $rows = $this->performance();

        $this->assertSame(['Consultation', 'Laboratory', 'Reception', 'Pharmacy'], array_column($rows, 'name'));
        $this->assertSame([45, 19, 13, 9], array_column($rows, 'avg_minutes'));
        $this->assertSame([2, 1, 2, 1], array_column($rows, 'stays'));
    }

    public function test_bars_are_relative_to_the_slowest_department(): void
    {
        $this->visitWith([[0, VisitEventType::Registered, $this->consultation], [40, VisitEventType::Completed, $this->consultation]]);
        $this->visitWith([[0, VisitEventType::Registered, $this->pharmacy], [10, VisitEventType::Completed, $this->pharmacy]]);

        $this->assertSame([1.0, 0.25], array_column($this->performance(), 'share_of_slowest'));
    }

    public function test_a_visit_through_three_departments_gives_each_its_own_time(): void
    {
        $this->visitWith([
            [0, VisitEventType::Registered, $this->reception],
            [12, VisitEventType::Transferred, $this->consultation],
            [45, VisitEventType::Transferred, $this->laboratory],
            [60, VisitEventType::Completed, $this->laboratory],
        ]);

        $byName = array_column($this->performance(), 'avg_minutes', 'name');

        $this->assertSame(['Consultation' => 33, 'Laboratory' => 15, 'Reception' => 12], $byName);
    }

    public function test_a_stay_still_going_is_not_averaged_in(): void
    {
        $this->visitWith([
            [0, VisitEventType::Registered, $this->reception],
            [12, VisitEventType::Transferred, $this->consultation],
        ]);

        $this->assertSame(['Reception'], array_column($this->performance(), 'name'));
    }

    public function test_a_stay_that_ended_in_cancellation_is_not_averaged_in(): void
    {
        $this->visitWith([
            [0, VisitEventType::Registered, $this->pharmacy],
            [90, VisitEventType::Cancelled, $this->pharmacy],
        ], attributes: ['status' => VisitStatus::Cancelled]);

        $this->assertSame([], $this->performance());
    }

    public function test_departments_tied_on_average_are_listed_alphabetically(): void
    {
        $this->visitWith([[0, VisitEventType::Registered, $this->pharmacy], [10, VisitEventType::Completed, $this->pharmacy]]);
        $this->visitWith([[0, VisitEventType::Registered, $this->laboratory], [10, VisitEventType::Completed, $this->laboratory]]);

        $this->assertSame(['Laboratory', 'Pharmacy'], array_column($this->performance(), 'name'));
    }

    public function test_performance_is_only_todays_and_only_this_facilitys(): void
    {
        $this->visitWith([[0, VisitEventType::Registered, $this->pharmacy], [9, VisitEventType::Completed, $this->pharmacy]]);
        $this->visitWith([[0, VisitEventType::Registered, $this->pharmacy], [500, VisitEventType::Completed, $this->pharmacy]], now()->subDay());
        $otherFacility = Facility::factory()->create();
        $theirs = Department::factory()->for($otherFacility)->create(['name' => 'Their Ward']);
        $this->visitWith([[0, VisitEventType::Registered, $theirs], [77, VisitEventType::Completed, $theirs]], facility: $otherFacility);

        $rows = $this->performance();

        $this->assertSame(['Pharmacy'], array_column($rows, 'name'));
        $this->assertSame([9], array_column($rows, 'avg_minutes'));
        $this->assertSame(['Their Ward'], array_column($this->performance($otherFacility), 'name'));
    }

    public function test_with_nothing_finished_yet_there_is_nothing_to_rank(): void
    {
        $this->assertSame([], $this->performance());
    }

    /**
     * The same journey done through the real screens' actions, with the clock
     * moving on between steps, so what is measured is what the app recorded.
     */
    public function test_a_patient_taken_through_reception_consultation_and_the_laboratory_is_measured_correctly(): void
    {
        Event::fake();
        $receptionist = User::factory()->for($this->facility)->receptionist()->create();
        $doctor = User::factory()->for($this->facility)->doctor()->create();
        $transitioner = app(VisitStatusTransitioner::class);
        $step = fn (int $minutes) => $this->travelTo(now()->addMinutes($minutes));

        $visit = app(RegisterPatientVisit::class)->handle($receptionist, [
            'phone' => '+254712345678',
            'name' => 'Wanjiru Kamau',
            'department_id' => $this->reception->id,
        ]);
        $step(6);
        $transitioner->transition($visit, VisitStatus::Called, $receptionist);
        $step(1);
        $transitioner->transition($visit->refresh(), VisitStatus::InService, $receptionist);
        $step(4);
        $transitioner->transferToDepartment($visit->refresh(), $this->consultation, $receptionist);
        $step(15);
        $transitioner->transition($visit->refresh(), VisitStatus::Called, $doctor);
        $step(2);
        $transitioner->transition($visit->refresh(), VisitStatus::InService, $doctor);
        $step(20);
        $transitioner->transferToDepartment($visit->refresh(), $this->laboratory, $doctor);
        $step(9);
        $transitioner->transition($visit->refresh(), VisitStatus::Called, $doctor);
        $step(1);
        $transitioner->transition($visit->refresh(), VisitStatus::InService, $doctor);
        $step(7);
        $transitioner->transition($visit->refresh(), VisitStatus::Completed, $doctor);

        $byName = array_column($this->performance(), 'avg_minutes', 'name');
        $summary = $this->summary();

        // Reception 6+1+4, consultation 15+2+20, laboratory 9+1+7.
        $this->assertSame(['Consultation' => 37, 'Laboratory' => 17, 'Reception' => 11], $byName);
        $this->assertSame(6, $summary['avg_wait_minutes']);
        $this->assertSame(6, $summary['longest_wait_minutes']);
        $this->assertSame(1, $summary['patients_today']);
        $this->assertSame(1, $summary['completed']);
    }
}
