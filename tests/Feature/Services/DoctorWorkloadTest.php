<?php

namespace Tests\Feature\Services;

use App\Enums\DepartmentType;
use App\Enums\VisitEventType as Event;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorWorkload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\LogsVisitHistory;
use Tests\TestCase;

class DoctorWorkloadTest extends TestCase
{
    use LogsVisitHistory;
    use RefreshDatabase;

    private Facility $facility;

    private Department $consultation;

    private Department $laboratory;

    private User $wanjiku;

    private User $kamau;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now(config('careflow.timezone'))->setTime(12, 0));

        $this->facility = Facility::factory()->create();
        $this->consultation = Department::factory()->for($this->facility)->assigningDoctors()->create(['type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Laboratory]);
        $this->wanjiku = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Wanjiku Mwangi', 'department_id' => $this->consultation->id]);
        $this->kamau = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Kamau Njoroge', 'department_id' => $this->consultation->id]);
    }

    /**
     * A visit that ended up with this doctor, with the given log.
     *
     * @param  list<array{int|float, Event, Department|null}>  $steps
     * @param  array<string, mixed>  $attributes
     */
    private function seenBy(User $doctor, array $steps, array $attributes = []): Visit
    {
        return $this->visitWith($steps, attributes: [
            'department_id' => $this->consultation->id,
            'assigned_doctor_id' => $doctor->id,
            'doctor_queue_number' => 1,
            ...$attributes,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function report(): array
    {
        return collect(app(DoctorWorkload::class)->lastThirtyDays($this->facility->id))->keyBy('doctor_id')->all();
    }

    private function seenOnce(User $doctor, int $called, int $started, int $completed): Visit
    {
        return $this->seenBy($doctor, [
            [0, Event::Registered, $this->consultation],
            [0, Event::DoctorAssigned, $this->consultation],
            [$called, Event::Called, $this->consultation],
            [$started, Event::Started, $this->consultation],
            [$completed, Event::Completed, $this->consultation],
        ], ['status' => VisitStatus::Completed]);
    }

    public function test_counts_patients_and_averages_the_wait_to_be_called_and_the_time_with_the_doctor(): void
    {
        $this->seenOnce($this->wanjiku, called: 10, started: 12, completed: 30);   // waited 10, consulted 18
        $this->seenOnce($this->wanjiku, called: 20, started: 21, completed: 33);   // waited 20, consulted 12
        $this->seenOnce($this->kamau, called: 6, started: 8, completed: 20);       // waited 6, consulted 12

        $report = $this->report();

        $this->assertSame(
            ['doctor_id' => $this->wanjiku->id, 'name' => 'Dr. Wanjiku Mwangi', 'patients' => 2, 'avg_wait_minutes' => 15, 'avg_consultation_minutes' => 15],
            $report[$this->wanjiku->id],
        );
        $this->assertSame(
            ['doctor_id' => $this->kamau->id, 'name' => 'Dr. Kamau Njoroge', 'patients' => 1, 'avg_wait_minutes' => 6, 'avg_consultation_minutes' => 12],
            $report[$this->kamau->id],
        );
    }

    public function test_the_busiest_doctor_is_first(): void
    {
        $this->seenOnce($this->kamau, 5, 6, 16);
        $this->seenOnce($this->wanjiku, 5, 6, 16);
        $this->seenOnce($this->wanjiku, 5, 6, 16);

        $this->assertSame([$this->wanjiku->id, $this->kamau->id], array_column(app(DoctorWorkload::class)->lastThirtyDays($this->facility->id), 'doctor_id'));
    }

    public function test_a_patient_handed_over_counts_for_the_doctor_they_ended_with_and_waits_from_the_handover(): void
    {
        $this->seenBy($this->kamau, [
            [0, Event::Registered, $this->consultation],
            [0, Event::DoctorAssigned, $this->consultation],
            [5, Event::Called, $this->consultation],
            [10, Event::DoctorReassigned, $this->consultation],
            [25, Event::Called, $this->consultation],
            [26, Event::Started, $this->consultation],
            [40, Event::Completed, $this->consultation],
        ], ['status' => VisitStatus::Completed]);

        $report = $this->report();

        $this->assertArrayNotHasKey($this->wanjiku->id, $report);
        $this->assertSame(1, $report[$this->kamau->id]['patients']);
        $this->assertSame(15, $report[$this->kamau->id]['avg_wait_minutes'], 'From the handover at 10 to being called at 25: not from registering.');
        $this->assertSame(14, $report[$this->kamau->id]['avg_consultation_minutes']);
    }

    public function test_a_doctors_time_stops_when_the_patient_is_sent_on_and_care_elsewhere_is_not_theirs(): void
    {
        $this->seenBy($this->wanjiku, [
            [0, Event::Registered, $this->consultation],
            [0, Event::DoctorAssigned, $this->consultation],
            [5, Event::Called, $this->consultation],
            [6, Event::Started, $this->consultation],
            [16, Event::Transferred, $this->laboratory],
            [20, Event::Started, $this->laboratory],
            [50, Event::Completed, $this->laboratory],
        ], ['status' => VisitStatus::Completed]);

        $this->assertSame(10, $this->report()[$this->wanjiku->id]['avg_consultation_minutes']);
    }

    public function test_an_average_nobody_got_far_enough_to_have_is_null_not_zero(): void
    {
        $this->seenBy($this->kamau, [
            [0, Event::Registered, $this->consultation],
            [0, Event::DoctorAssigned, $this->consultation],
        ]);

        $this->assertSame(
            ['doctor_id' => $this->kamau->id, 'name' => 'Dr. Kamau Njoroge', 'patients' => 1, 'avg_wait_minutes' => null, 'avg_consultation_minutes' => null],
            $this->report()[$this->kamau->id],
        );
    }

    public function test_cancelled_visits_old_visits_and_other_facilities_are_left_out(): void
    {
        $this->seenOnce($this->wanjiku, 5, 6, 16);
        $this->seenBy($this->wanjiku, [[0, Event::Registered, $this->consultation], [0, Event::DoctorAssigned, $this->consultation]], ['status' => VisitStatus::Cancelled]);
        $this->visitWith(
            [[0, Event::Registered, $this->consultation], [0, Event::DoctorAssigned, $this->consultation], [5, Event::Called, $this->consultation]],
            day: now()->subDays(40),
            attributes: ['department_id' => $this->consultation->id, 'assigned_doctor_id' => $this->wanjiku->id, 'doctor_queue_number' => 1],
        );

        $this->assertSame(1, $this->report()[$this->wanjiku->id]['patients']);

        $otherFacility = Facility::factory()->create();
        $this->assertSame([], app(DoctorWorkload::class)->lastThirtyDays($otherFacility->id));
    }

    public function test_visits_that_never_had_a_doctor_are_not_in_anyones_numbers(): void
    {
        $this->visitWith([[0, Event::Registered, $this->consultation], [5, Event::Called, $this->consultation]], attributes: ['department_id' => $this->consultation->id]);

        $this->assertSame([], app(DoctorWorkload::class)->lastThirtyDays($this->facility->id));
    }

    public function test_a_patient_accepted_from_home_only_starts_waiting_for_the_doctor_when_they_arrive(): void
    {
        $this->seenBy($this->wanjiku, [
            [0, Event::Registered, $this->consultation],
            [0, Event::DoctorAssigned, $this->consultation],
            [80, Event::CheckedIn, $this->consultation],
            [90, Event::Called, $this->consultation],
            [91, Event::Started, $this->consultation],
            [101, Event::Completed, $this->consultation],
        ], ['status' => VisitStatus::Completed]);

        $row = $this->report()[$this->wanjiku->id];

        $this->assertSame(10, $row['avg_wait_minutes'], 'Ten minutes in the waiting room, not ninety since being accepted.');
        $this->assertSame(10, $row['avg_consultation_minutes']);
    }
}
