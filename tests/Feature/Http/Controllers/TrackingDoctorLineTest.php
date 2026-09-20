<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DepartmentType;
use App\Enums\JourneyStepState;
use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use App\Services\VisitTracker;
use App\Support\JourneyStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a patient sees when they are in one doctor's own line: that doctor, their
 * own place in that line, and never how many patients the doctor has in all.
 */
class TrackingDoctorLineTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $reception;

    private Department $consultation;

    private Department $laboratory;

    private User $wanjiku;

    private User $kamau;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->reception = Department::factory()->for($this->facility)->create(['name' => 'Reception', 'type' => DepartmentType::Reception]);
        $this->consultation = Department::factory()->for($this->facility)->assigningDoctors()->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
        $this->wanjiku = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Wanjiku Mwangi', 'department_id' => $this->consultation->id]);
        $this->kamau = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Kamau Njoroge', 'department_id' => $this->consultation->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function inLine(User $doctor, int $number, VisitStatus $status = VisitStatus::Waiting, array $attributes = []): Visit
    {
        return Visit::factory()->inLineOf($doctor, $number)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create()->id,
            'queue_number' => 50 + $number,
            'department_entered_at' => now()->subMinutes(60 - $number),
            'status' => $status,
            ...$attributes,
        ]);
    }

    private function log(Visit $visit, VisitEventType $event, ?Department $department = null, ?array $meta = null): void
    {
        VisitEvent::factory()->create(['visit_id' => $visit->id, 'department_id' => ($department ?? $this->consultation)->id, 'event' => $event, 'meta' => $meta]);
    }

    public function test_the_patient_is_told_their_doctor_their_number_in_that_doctors_line_and_who_is_being_seen(): void
    {
        $this->inLine($this->wanjiku, 1, VisitStatus::InService);
        $this->inLine($this->wanjiku, 2);
        $this->inLine($this->wanjiku, 3);
        $me = $this->inLine($this->wanjiku, 4);
        // Dr. Kamau's line has nothing to do with it.
        $this->inLine($this->kamau, 1, VisitStatus::InService);
        foreach (range(2, 6) as $number) {
            $this->inLine($this->kamau, $number);
        }

        $snapshot = app(VisitTracker::class)->snapshot($me);

        $this->assertSame('Dr. Wanjiku Mwangi', $snapshot->doctorName);
        $this->assertSame('C-4', $snapshot->queueLabel);
        $this->assertSame(2, $snapshot->patientsAhead, 'Only the two waiting ahead of them in Dr. Wanjiku\'s line: not the one being seen, not Dr. Kamau\'s.');
        $this->assertSame('C-1', $snapshot->serving);
        $this->assertSame('Waiting for Dr. Wanjiku Mwangi', $snapshot->stage);
    }

    public function test_the_page_shows_the_doctor_and_position_but_never_the_doctors_total_workload(): void
    {
        $this->inLine($this->wanjiku, 1, VisitStatus::InService);
        $this->inLine($this->wanjiku, 2, VisitStatus::Called);
        $this->inLine($this->wanjiku, 3);
        $this->inLine($this->wanjiku, 4);
        $me = $this->inLine($this->wanjiku, 5);
        $this->inLine($this->wanjiku, 6);
        $this->inLine($this->wanjiku, 7);

        $this->get(route('tracking.show', $me->tracking_token))
            ->assertOk()
            ->assertSeeText('Your doctor: Dr. Wanjiku Mwangi')
            ->assertSeeText('Queue C-5')
            ->assertSeeText('Currently seeing: C-1')
            ->assertSeeText('2 patients ahead of you')
            ->assertSeeText('Waiting for Dr. Wanjiku Mwangi')
            ->assertDontSeeText('7 patients')
            ->assertDontSeeText('patients waiting')
            ->assertDontSeeText('workload');
    }

    public function test_the_wait_estimate_is_for_the_one_doctors_line_not_shared_across_the_department(): void
    {
        // Three doctors in the department: a shared queue would clear about three times as fast.
        User::factory()->for($this->facility)->doctor()->onDuty()->create(['department_id' => $this->consultation->id]);
        $this->inLine($this->wanjiku, 1);
        $this->inLine($this->wanjiku, 2);
        $me = $this->inLine($this->wanjiku, 3);

        $snapshot = app(VisitTracker::class)->snapshot($me);

        // Two patients at the default 8 minutes each is 16, give or take a fifth, in steps of five.
        $this->assertSame('15–20 minutes', $snapshot->estimate->label());
    }

    public function test_patients_without_a_doctor_in_the_same_department_do_not_count_ahead_of_someone_in_a_doctors_line(): void
    {
        Visit::factory()->for($this->facility)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create()->id,
            'department_id' => $this->consultation->id,
            'department_entered_at' => now()->subHours(3),
        ]);
        $me = $this->inLine($this->wanjiku, 1);

        $this->assertSame(0, app(VisitTracker::class)->snapshot($me)->patientsAhead);
    }

    public function test_the_stage_follows_the_patient_being_called_and_being_seen_by_their_doctor(): void
    {
        $called = $this->inLine($this->wanjiku, 1, VisitStatus::Called);
        $seen = $this->inLine($this->wanjiku, 2, VisitStatus::InService);

        $this->assertSame('It\'s your turn — please go to Consultation to see Dr. Wanjiku Mwangi', app(VisitTracker::class)->snapshot($called)->stage);
        $this->assertSame('Being seen by Dr. Wanjiku Mwangi', app(VisitTracker::class)->snapshot($seen)->stage);
    }

    public function test_a_finished_or_cancelled_visit_is_told_so_and_not_that_it_is_still_waiting_for_the_doctor(): void
    {
        $done = $this->inLine($this->wanjiku, 1, VisitStatus::Completed);
        $cancelled = $this->inLine($this->wanjiku, 2, VisitStatus::Cancelled);

        $this->assertSame('Your visit is complete', app(VisitTracker::class)->snapshot($done)->stage);
        $this->assertSame('Your visit was cancelled', app(VisitTracker::class)->snapshot($cancelled)->stage);
    }

    public function test_the_checklist_for_a_patient_registered_straight_into_a_doctors_line(): void
    {
        $me = $this->inLine($this->wanjiku, 3);
        $this->log($me, VisitEventType::Registered);
        $this->log($me, VisitEventType::DoctorAssigned, meta: ['doctor_id' => $this->wanjiku->id]);

        $steps = app(VisitTracker::class)->snapshot($me)->steps;

        $this->assertSame(
            [['Registered', JourneyStepState::Done, null], ['Doctor assigned: Dr. Wanjiku Mwangi', JourneyStepState::Done, null], ['Waiting for Dr. Wanjiku Mwangi', JourneyStepState::Current, 'C-3']],
            array_map(fn (JourneyStep $step) => [$step->department, $step->state, $step->number], $steps),
        );
    }

    public function test_the_checklist_keeps_the_earlier_departments_for_a_patient_sent_on_to_a_doctor(): void
    {
        $me = $this->inLine($this->wanjiku, 3, VisitStatus::Called);
        $this->log($me, VisitEventType::Registered, $this->reception);
        $this->log($me, VisitEventType::Transferred);
        $this->log($me, VisitEventType::DoctorAssigned, meta: ['doctor_id' => $this->wanjiku->id]);

        $steps = app(VisitTracker::class)->snapshot($me)->steps;

        $this->assertSame(
            ['Reception', 'Doctor assigned: Dr. Wanjiku Mwangi', 'Called by Dr. Wanjiku Mwangi'],
            array_map(fn (JourneyStep $step) => $step->department, $steps),
        );
    }

    public function test_after_a_handover_the_page_says_the_doctor_changed_with_the_new_doctor_and_place_on_the_next_poll(): void
    {
        $me = $this->inLine($this->wanjiku, 2);
        $this->log($me, VisitEventType::Registered);
        $this->log($me, VisitEventType::DoctorAssigned, meta: ['doctor_id' => $this->wanjiku->id]);

        $this->get(route('tracking.show', $me->tracking_token))->assertDontSeeText('Your assigned doctor has changed');

        $me->update(['assigned_doctor_id' => $this->kamau->id, 'doctor_queue_number' => 6]);
        $this->log($me, VisitEventType::DoctorReassigned, meta: ['from_doctor_id' => $this->wanjiku->id, 'to_doctor_id' => $this->kamau->id, 'reason' => 'Emergency']);

        $poll = $this->getJson(route('tracking.status', $me->tracking_token))->assertOk()->json('html');

        $this->assertStringContainsString('Your assigned doctor has changed', $poll);
        $this->assertStringContainsString('Dr. Kamau Njoroge', $poll);
        $this->assertStringContainsString('C-6', $poll);
        $this->assertStringNotContainsString('Wanjiku', $poll);
    }

    public function test_a_patient_sent_on_to_the_laboratory_is_no_longer_shown_a_doctor(): void
    {
        $me = Visit::factory()->for($this->facility)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create()->id,
            'department_id' => $this->laboratory->id,
            'assigned_doctor_id' => $this->wanjiku->id,
            'doctor_queue_number' => null,
            'department_queue_number' => 4,
        ]);

        $this->get(route('tracking.show', $me->tracking_token))
            ->assertOk()
            ->assertSeeText('Queue L-4')
            ->assertDontSeeText('Your doctor')
            ->assertDontSeeText('Wanjiku')
            ->assertSeeText('Waiting for Laboratory');
    }

    public function test_a_department_without_doctor_lines_is_shown_exactly_as_before(): void
    {
        $me = Visit::factory()->for($this->facility)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create()->id,
            'department_id' => $this->laboratory->id,
            'department_queue_number' => 8,
        ]);

        $snapshot = app(VisitTracker::class)->snapshot($me);

        $this->assertNull($snapshot->doctorName);
        $this->assertFalse($snapshot->doctorChanged);
        $this->get(route('tracking.show', $me->tracking_token))->assertSeeText('Waiting for Laboratory')->assertDontSeeText('Your doctor');
    }
}
