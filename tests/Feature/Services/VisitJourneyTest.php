<?php

namespace Tests\Feature\Services;

use App\Actions\RegisterPatientVisit;
use App\Enums\DepartmentType;
use App\Enums\JourneyStepState;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use App\Services\VisitJourney;
use App\Services\VisitStatusTransitioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $reception;

    private Department $consultation;

    private Department $laboratory;

    private Department $pharmacy;

    private User $staff;

    private VisitStatusTransitioner $transitioner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->reception = Department::factory()->for($this->facility)->create(['name' => 'Reception', 'type' => DepartmentType::Reception]);
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
        $this->pharmacy = Department::factory()->for($this->facility)->create(['name' => 'Pharmacy', 'type' => DepartmentType::Pharmacy]);
        $this->staff = User::factory()->for($this->facility)->create();
        $this->transitioner = app(VisitStatusTransitioner::class);
    }

    private function register(?Department $department = null): Visit
    {
        return app(RegisterPatientVisit::class)->handle($this->staff, [
            'phone' => '+254712345678',
            'name' => 'Brian Kamau',
            'department_id' => $department?->id ?? $this->reception->id,
        ]);
    }

    /**
     * Be seen at the visit's current department, then be sent on to another.
     */
    private function seenThenSentTo(Visit $visit, Department $to): Visit
    {
        $visit = $this->transitioner->transition($visit, VisitStatus::Called, $this->staff);
        $visit = $this->transitioner->transition($visit, VisitStatus::InService, $this->staff);

        return $this->transitioner->transferToDepartment($visit, $to, $this->staff);
    }

    /**
     * @return list<array{string, string}>
     */
    private function journey(Visit $visit): array
    {
        return array_map(
            fn ($step) => [$step->department, $step->state->value],
            app(VisitJourney::class)->steps($visit->fresh()),
        );
    }

    public function test_a_patient_just_registered_is_at_their_first_department(): void
    {
        $visit = $this->register();

        $this->assertSame([['Reception', 'current']], $this->journey($visit));
    }

    public function test_the_departments_a_patient_has_left_are_done_and_the_one_they_are_in_is_current(): void
    {
        $visit = $this->seenThenSentTo($this->register(), $this->consultation);
        $visit = $this->seenThenSentTo($visit, $this->laboratory);

        $this->assertSame(
            [['Reception', 'done'], ['Consultation', 'done'], ['Laboratory', 'current']],
            $this->journey($visit),
        );
    }

    public function test_the_current_stop_carries_the_patients_number_there_and_earlier_stops_carry_none(): void
    {
        $visit = $this->seenThenSentTo($this->register(), $this->consultation);
        $visit = $this->seenThenSentTo($visit, $this->laboratory);

        $steps = app(VisitJourney::class)->steps($visit->fresh());

        $this->assertNull($steps[0]->number);
        $this->assertNull($steps[1]->number);
        $this->assertSame('L-1', $steps[2]->number);
    }

    public function test_on_the_first_stop_the_number_is_the_one_given_at_registration(): void
    {
        $visit = $this->register();

        $this->assertSame('#'.$visit->queue_number, app(VisitJourney::class)->steps($visit)[0]->number);
    }

    public function test_it_lists_only_departments_actually_reached_never_ones_still_to_come(): void
    {
        $visit = $this->seenThenSentTo($this->register(), $this->consultation);

        $names = array_column($this->journey($visit), 0);

        $this->assertSame(['Reception', 'Consultation'], $names);
        $this->assertNotContains('Laboratory', $names);
        $this->assertNotContains('Pharmacy', $names);
    }

    public function test_a_completed_visit_has_every_stop_done_and_nothing_current(): void
    {
        $visit = $this->seenThenSentTo($this->register(), $this->pharmacy);
        $visit = $this->transitioner->transition($visit, VisitStatus::Called, $this->staff);
        $visit = $this->transitioner->transition($visit, VisitStatus::InService, $this->staff);
        $visit = $this->transitioner->transition($visit, VisitStatus::Completed, $this->staff);

        $this->assertSame([['Reception', 'done'], ['Pharmacy', 'done']], $this->journey($visit));
        $this->assertFalse(app(VisitJourney::class)->isOpen($visit));
    }

    public function test_a_cancelled_visit_ends_on_a_cancelled_stop(): void
    {
        $visit = $this->seenThenSentTo($this->register(), $this->consultation);
        $visit = $this->transitioner->transition($visit, VisitStatus::Cancelled, $this->staff);

        $this->assertSame([['Reception', 'done'], ['Consultation', 'cancelled']], $this->journey($visit));
        $this->assertFalse(app(VisitJourney::class)->isOpen($visit));
    }

    public function test_an_open_visit_may_have_more_steps_to_come(): void
    {
        $this->assertTrue(app(VisitJourney::class)->isOpen($this->register()));
    }

    public function test_coming_back_to_a_department_later_is_a_separate_stop(): void
    {
        $visit = $this->seenThenSentTo($this->register(), $this->consultation);
        $visit = $this->seenThenSentTo($visit, $this->laboratory);
        $visit = $this->seenThenSentTo($visit, $this->consultation);

        $this->assertSame(
            [['Reception', 'done'], ['Consultation', 'done'], ['Laboratory', 'done'], ['Consultation', 'current']],
            $this->journey($visit),
        );
    }

    public function test_calling_and_starting_within_a_department_does_not_add_stops(): void
    {
        $visit = $this->register();
        $visit = $this->transitioner->transition($visit, VisitStatus::Called, $this->staff);
        $visit = $this->transitioner->transition($visit, VisitStatus::Waiting, $this->staff);
        $visit = $this->transitioner->transition($visit, VisitStatus::Called, $this->staff);
        $visit = $this->transitioner->transition($visit, VisitStatus::InService, $this->staff);

        $this->assertSame([['Reception', 'current']], $this->journey($visit));
    }

    public function test_a_journey_of_four_stops_builds_from_the_log_alone(): void
    {
        $visit = $this->seenThenSentTo($this->register(), $this->consultation);
        $visit = $this->seenThenSentTo($visit, $this->laboratory);
        $visit = $this->seenThenSentTo($visit, $this->pharmacy);

        $this->assertSame(
            [['Reception', 'done'], ['Consultation', 'done'], ['Laboratory', 'done'], ['Pharmacy', 'current']],
            $this->journey($visit),
        );
        $this->assertSame(JourneyStepState::Current, app(VisitJourney::class)->steps($visit->fresh())[3]->state);
    }

    public function test_a_visit_registered_with_no_department_starts_at_registration(): void
    {
        $visit = Visit::factory()->for($this->facility)->create(['department_id' => null]);
        VisitEvent::factory()->for($visit)->create(['department_id' => null]);

        $this->assertSame([['Registration', 'current']], $this->journey($visit));
    }

    public function test_a_visit_with_no_log_still_shows_where_it_is(): void
    {
        $visit = Visit::factory()->for($this->facility)->create(['department_id' => $this->consultation->id]);

        $this->assertSame([['Consultation', 'current']], $this->journey($visit));
    }
}
