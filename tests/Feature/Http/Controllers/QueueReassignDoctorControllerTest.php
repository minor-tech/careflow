<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DepartmentType;
use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Events\VisitDoctorChanged;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\QueueNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueueReassignDoctorControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $consultation;

    private Department $laboratory;

    private User $wanjiku;

    private User $kamau;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->consultation = Department::factory()->for($this->facility)->assigningDoctors()->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
        $this->wanjiku = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Wanjiku Mwangi', 'department_id' => $this->consultation->id]);
        $this->kamau = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Kamau Njoroge', 'department_id' => $this->consultation->id]);
        $this->admin = User::factory()->for($this->facility)->create();
    }

    private function inLineOfWanjiku(VisitStatus $status = VisitStatus::Waiting): Visit
    {
        return Visit::factory()->inLineOf($this->wanjiku, 1)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => 'Julius Maina'])->id,
            'queue_number' => 27,
            'status' => $status,
        ]);
    }

    private function handOver(User $by, Visit $visit, ?User $to = null, string $reason = 'Called to an emergency')
    {
        return $this->actingAs($by)->post(route('queue.reassign-doctor', $visit), ['doctor_id' => ($to ?? $this->kamau)->id, 'reason' => $reason]);
    }

    public function test_the_patients_own_doctor_hands_a_waiting_patient_to_another_doctor_and_they_join_the_back_of_that_line(): void
    {
        Event::fake([VisitDoctorChanged::class]);
        $visit = $this->inLineOfWanjiku();
        $this->travelTo(now()->addMinutes(20));

        $this->handOver($this->wanjiku, $visit)
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Julius Maina is now with Dr. Kamau Njoroge as C-1.');

        $visit->refresh();
        $this->assertSame($this->kamau->id, $visit->assigned_doctor_id);
        $this->assertSame(VisitStatus::Waiting, $visit->status);
        $this->assertTrue($visit->department_entered_at->isSameMinute(now()), 'They join the back of the new line, not where they were.');
        $this->assertFalse($visit->almost_turn_notified);
        Event::assertDispatched(VisitDoctorChanged::class, fn (VisitDoctorChanged $event) => $event->visit->is($visit));
    }

    public function test_the_new_doctors_next_personal_number_is_used(): void
    {
        $visit = $this->inLineOfWanjiku();

        // Two patients have already joined Dr. Kamau's line today.
        app(QueueNumberGenerator::class)->next($this->facility->id, $this->consultation->id, $this->kamau->id);
        app(QueueNumberGenerator::class)->next($this->facility->id, $this->consultation->id, $this->kamau->id);

        $this->handOver($this->admin, $visit);

        $this->assertSame(3, $visit->fresh()->doctor_queue_number);
        $this->assertSame('C-3', $visit->fresh()->load('department')->queueLabel());
    }

    public function test_a_patient_who_had_been_called_goes_back_to_waiting_in_the_new_line(): void
    {
        $visit = $this->inLineOfWanjiku(VisitStatus::Called);

        $this->handOver($this->admin, $visit)->assertSessionHasNoErrors();

        $this->assertSame(VisitStatus::Waiting, $visit->fresh()->status);
        $this->assertSame($this->kamau->id, $visit->fresh()->assigned_doctor_id);
    }

    public function test_every_reassignment_is_logged_with_who_from_whom_to_whom_and_why(): void
    {
        $visit = $this->inLineOfWanjiku();

        $this->handOver($this->wanjiku, $visit, reason: 'Called to an emergency');

        $event = $visit->events()->sole();
        $this->assertSame(VisitEventType::DoctorReassigned, $event->event);
        $this->assertSame('doctor_reassigned', $event->getRawOriginal('event'));
        $this->assertSame($this->wanjiku->id, $event->user_id);
        $this->assertSame($this->consultation->id, $event->department_id);
        $this->assertSame([
            'from_doctor_id' => $this->wanjiku->id,
            'to_doctor_id' => $this->kamau->id,
            'reason' => 'Called to an emergency',
        ], $event->meta);
    }

    public function test_an_admin_can_reassign_too(): void
    {
        $visit = $this->inLineOfWanjiku();

        $this->handOver($this->admin, $visit)->assertSessionHasNoErrors();

        $this->assertSame($this->admin->id, $visit->events()->sole()->user_id);
    }

    public function test_it_is_blocked_once_the_consultation_has_started_and_nothing_changes(): void
    {
        Event::fake([VisitDoctorChanged::class]);
        $visit = $this->inLineOfWanjiku(VisitStatus::InService);

        $this->handOver($this->wanjiku, $visit)
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasErrors(['queue' => 'Cannot reassign after consultation has started.']);

        $this->assertSame($this->wanjiku->id, $visit->fresh()->assigned_doctor_id);
        $this->assertSame(VisitStatus::InService, $visit->fresh()->status);
        $this->assertCount(0, $visit->events);
        Event::assertNotDispatched(VisitDoctorChanged::class);
    }

    /**
     * @return array<string, array{VisitStatus}>
     */
    public static function visitsThatAreOver(): array
    {
        return ['completed' => [VisitStatus::Completed], 'cancelled' => [VisitStatus::Cancelled]];
    }

    #[DataProvider('visitsThatAreOver')]
    public function test_a_finished_visit_cannot_be_reassigned(VisitStatus $status): void
    {
        $visit = $this->inLineOfWanjiku($status);

        $this->handOver($this->admin, $visit)->assertSessionHasErrors('queue');

        $this->assertSame($this->wanjiku->id, $visit->fresh()->assigned_doctor_id);
    }

    public function test_the_reason_is_required_and_kept_short(): void
    {
        $visit = $this->inLineOfWanjiku();

        $this->handOver($this->admin, $visit, reason: '')
            ->assertSessionHasErrors(['reason' => 'Say why the patient is being reassigned. It is kept on the record.']);
        $this->handOver($this->admin, $visit, reason: str_repeat('x', 256))->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('queue.reassign-doctor', $visit), ['reason' => 'Emergency'])
            ->assertSessionHasErrors(['doctor_id' => 'Choose the doctor to hand this patient to.']);

        $this->assertSame($this->wanjiku->id, $visit->fresh()->assigned_doctor_id);
        $this->assertCount(0, $visit->events);
    }

    public function test_the_new_doctor_must_be_on_duty_in_the_same_department(): void
    {
        $visit = $this->inLineOfWanjiku();
        $away = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]);
        $labDoctor = User::factory()->for($this->facility)->doctor()->onDuty()->create(['department_id' => $this->laboratory->id]);
        $suspended = User::factory()->for($this->facility)->doctor()->onDuty()->suspended()->create(['department_id' => $this->consultation->id]);

        foreach ([$away, $labDoctor, $suspended] as $notAvailable) {
            $this->handOver($this->admin, $visit, $notAvailable)->assertSessionHasErrors('queue');
        }

        $this->assertSame($this->wanjiku->id, $visit->fresh()->assigned_doctor_id);
        $this->assertCount(0, $visit->events);
    }

    public function test_a_doctor_from_another_facility_is_refused(): void
    {
        $visit = $this->inLineOfWanjiku();
        $outsider = User::factory()->for(Facility::factory()->create())->doctor()->onDuty()->create();

        $this->handOver($this->admin, $visit, $outsider)->assertSessionHasErrors('queue');

        $this->assertSame($this->wanjiku->id, $visit->fresh()->assigned_doctor_id);
    }

    public function test_handing_a_patient_to_the_doctor_they_already_have_is_refused(): void
    {
        $visit = $this->inLineOfWanjiku();

        $this->handOver($this->admin, $visit, $this->wanjiku)
            ->assertSessionHasErrors(['queue' => 'This patient is already assigned to Dr. Wanjiku Mwangi.']);

        $this->assertCount(0, $visit->events);
    }

    public function test_a_patient_with_no_doctor_yet_is_assigned_one_and_logged_as_an_assignment(): void
    {
        $visit = Visit::factory()->for($this->facility)->create(['department_id' => $this->consultation->id]);

        $this->handOver($this->admin, $visit, reason: 'Department now assigns doctors')->assertSessionHasNoErrors();

        $event = $visit->events()->sole();
        $this->assertSame(VisitEventType::DoctorAssigned, $event->event);
        $this->assertSame(['doctor_id' => $this->kamau->id, 'reason' => 'Department now assigns doctors'], $event->meta);
        $this->assertSame(1, $visit->fresh()->doctor_queue_number);
    }

    public function test_a_department_that_does_not_assign_doctors_has_nothing_to_reassign(): void
    {
        $visit = Visit::factory()->for($this->facility)->create(['department_id' => $this->laboratory->id]);

        $this->handOver($this->admin, $visit)
            ->assertSessionHasErrors(['queue' => 'This patient\'s department doesn\'t assign patients to doctors.']);

        $this->assertNull($visit->fresh()->assigned_doctor_id);
    }

    public function test_another_doctor_a_nurse_and_a_receptionist_cannot_reassign(): void
    {
        $visit = $this->inLineOfWanjiku();

        foreach ([$this->kamau, User::factory()->for($this->facility)->nurse()->create(['department_id' => $this->consultation->id]), User::factory()->for($this->facility)->receptionist()->create(['department_id' => $this->consultation->id])] as $staff) {
            $this->handOver($staff, $visit, $this->kamau)->assertForbidden();
        }

        $this->assertSame($this->wanjiku->id, $visit->fresh()->assigned_doctor_id);
        $this->assertCount(0, $visit->events);
    }

    public function test_another_facilitys_admin_gets_not_found(): void
    {
        $visit = $this->inLineOfWanjiku();
        $outsider = User::factory()->for(Facility::factory()->create())->create();

        $this->handOver($outsider, $visit)->assertNotFound();

        $this->assertSame($this->wanjiku->id, $visit->fresh()->assigned_doctor_id);
    }
}
