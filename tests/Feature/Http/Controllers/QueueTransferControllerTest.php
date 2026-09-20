<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\RegisterPatientVisit;
use App\Enums\DepartmentType;
use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\QueueCounter;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueueTransferControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $reception;

    private Department $consultation;

    private Department $laboratory;

    private Department $pharmacy;

    private User $receptionist;

    private User $doctor;

    private User $labTech;

    private User $pharmacist;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->reception = Department::factory()->for($this->facility)->create(['name' => 'Reception', 'type' => DepartmentType::Reception]);
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
        $this->pharmacy = Department::factory()->for($this->facility)->create(['name' => 'Pharmacy', 'type' => DepartmentType::Pharmacy]);
        $this->receptionist = User::factory()->for($this->facility)->receptionist()->create(['department_id' => $this->reception->id]);
        $this->doctor = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]);
        $this->labTech = User::factory()->for($this->facility)->nurse()->create(['department_id' => $this->laboratory->id]);
        $this->pharmacist = User::factory()->for($this->facility)->nurse()->create(['department_id' => $this->pharmacy->id]);
        $this->admin = User::factory()->for($this->facility)->create();
    }

    private function inService(Department $department, string $name = 'Brian Kamau', int $number = 27): Visit
    {
        return Visit::factory()->for($this->facility)->create([
            'department_id' => $department->id,
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => $name])->id,
            'queue_number' => $number,
            'status' => VisitStatus::InService,
        ]);
    }

    private function staffOf(Department $department): User
    {
        return User::where('department_id', $department->id)->firstOrFail();
    }

    public function test_a_doctor_sends_a_patient_being_served_to_the_laboratory_where_they_wait_afresh(): void
    {
        $visit = $this->inService($this->consultation);

        $this->actingAs($this->doctor)
            ->post(route('queue.transfer', [$visit, $this->laboratory]))
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Brian Kamau is now waiting in Laboratory as L-1.');

        $visit->refresh();
        $this->assertSame($this->laboratory->id, $visit->department_id);
        $this->assertSame(VisitStatus::Waiting, $visit->status);
        $this->assertSame(1, $visit->department_queue_number);
        $this->assertNotNull($visit->department_entered_at);
    }

    public function test_the_patients_registration_number_never_changes_however_often_they_are_sent_on(): void
    {
        $visit = $this->inService($this->consultation, number: 27);

        $this->actingAs($this->doctor)->post(route('queue.transfer', [$visit, $this->laboratory]));

        $this->assertSame(27, $visit->fresh()->queue_number);
    }

    public function test_the_transfer_is_logged_with_the_new_department_and_who_sent_them(): void
    {
        $visit = $this->inService($this->consultation);

        $this->actingAs($this->doctor)->post(route('queue.transfer', [$visit, $this->laboratory]));

        $event = $visit->events->sole();
        $this->assertSame(VisitEventType::Transferred, $event->event);
        $this->assertSame($this->laboratory->id, $event->department_id);
        $this->assertSame($this->doctor->id, $event->user_id);
    }

    public function test_two_patients_sent_to_the_laboratory_back_to_back_get_sequential_local_numbers(): void
    {
        $first = $this->inService($this->consultation, 'First Patient', 27);
        $second = $this->inService($this->consultation, 'Second Patient', 28);

        $this->actingAs($this->doctor)->post(route('queue.transfer', [$first, $this->laboratory]));
        $this->actingAs($this->doctor)->post(route('queue.transfer', [$second, $this->laboratory]));

        $this->assertSame([1, 2], [$first->fresh()->department_queue_number, $second->fresh()->department_queue_number]);
        $this->assertSame([27, 28], [$first->fresh()->queue_number, $second->fresh()->queue_number]);
    }

    public function test_each_departments_local_numbers_are_independent(): void
    {
        $toLab = $this->inService($this->consultation, 'To Lab', 27);
        $toPharmacy = $this->inService($this->consultation, 'To Pharmacy', 28);
        $anotherToLab = $this->inService($this->consultation, 'Another To Lab', 29);

        $this->actingAs($this->doctor)->post(route('queue.transfer', [$toLab, $this->laboratory]));
        $this->actingAs($this->doctor)->post(route('queue.transfer', [$toPharmacy, $this->pharmacy]));
        $this->actingAs($this->doctor)->post(route('queue.transfer', [$anotherToLab, $this->laboratory]));

        $this->assertSame(1, $toLab->fresh()->department_queue_number);
        $this->assertSame(1, $toPharmacy->fresh()->department_queue_number);
        $this->assertSame(2, $anotherToLab->fresh()->department_queue_number);
    }

    public function test_the_laboratory_now_sees_the_patient_by_their_lab_number_and_the_doctor_no_longer_does(): void
    {
        $visit = $this->inService($this->consultation, 'Brian Kamau', 27);
        $this->actingAs($this->doctor)->post(route('queue.transfer', [$visit, $this->laboratory]));

        $this->actingAs($this->labTech)
            ->get(route('queue.index'))
            ->assertSeeText('Brian Kamau')
            ->assertSeeText('L-1')
            ->assertSee(route('queue.call', $visit), false);

        $this->actingAs($this->doctor)
            ->get(route('queue.index'))
            ->assertDontSeeText('Brian Kamau');
    }

    public function test_once_sent_on_the_original_doctor_can_no_longer_act_on_the_visit(): void
    {
        $visit = $this->inService($this->consultation);
        $this->actingAs($this->doctor)->post(route('queue.transfer', [$visit, $this->laboratory]));

        $this->actingAs($this->doctor)->post(route('queue.call', $visit))->assertForbidden();
        $this->actingAs($this->doctor)->post(route('queue.transfer', [$visit, $this->pharmacy]))->assertForbidden();
    }

    public function test_a_visit_goes_through_four_departments_in_a_row_without_a_problem(): void
    {
        $visit = app(RegisterPatientVisit::class)->handle($this->receptionist, [
            'phone' => '+254712345678',
            'name' => 'Brian Kamau',
            'department_id' => $this->reception->id,
        ]);
        $registrationNumber = $visit->queue_number;

        foreach ([$this->consultation, $this->laboratory, $this->pharmacy] as $next) {
            $current = $visit->fresh()->department;
            $staff = $this->staffOf($current);

            $this->actingAs($staff)->post(route('queue.call', $visit))->assertSessionHasNoErrors();
            $this->actingAs($staff)->post(route('queue.start', $visit))->assertSessionHasNoErrors();
            $this->actingAs($staff)->post(route('queue.transfer', [$visit, $next]))->assertSessionHasNoErrors();

            $visit->refresh();
            $this->assertSame($next->id, $visit->department_id);
            $this->assertSame(VisitStatus::Waiting, $visit->status);
        }

        // The last stop is seen through and the visit completed.
        $this->actingAs($this->pharmacist)->post(route('queue.call', $visit));
        $this->actingAs($this->pharmacist)->post(route('queue.start', $visit));
        $this->actingAs($this->pharmacist)->post(route('queue.complete', $visit))->assertSessionHasNoErrors();

        $visit->refresh();
        $this->assertSame(VisitStatus::Completed, $visit->status);
        $this->assertSame($registrationNumber, $visit->queue_number);
        $this->assertSame(
            [VisitEventType::Registered, VisitEventType::Called, VisitEventType::Started,
                VisitEventType::Transferred, VisitEventType::Called, VisitEventType::Started,
                VisitEventType::Transferred, VisitEventType::Called, VisitEventType::Started,
                VisitEventType::Transferred, VisitEventType::Called, VisitEventType::Started, VisitEventType::Completed],
            $visit->events->pluck('event')->all(),
        );
        $this->assertSame(
            [$this->reception->id, $this->consultation->id, $this->laboratory->id, $this->pharmacy->id],
            $visit->events->pluck('department_id')->unique()->values()->all(),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function anyDepartment(): array
    {
        return ['reception' => ['reception'], 'consultation' => ['consultation'], 'laboratory' => ['laboratory'], 'pharmacy' => ['pharmacy']];
    }

    #[DataProvider('anyDepartment')]
    public function test_a_visit_can_be_completed_as_the_final_step_from_any_department(string $department): void
    {
        $where = $this->{$department};
        $visit = $this->inService($where);

        $this->actingAs($this->staffOf($where))
            ->post(route('queue.complete', $visit))
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasNoErrors();

        $visit->refresh();
        $this->assertSame(VisitStatus::Completed, $visit->status);
        $this->assertNotNull($visit->completed_at);
        $this->assertSame($where->id, $visit->department_id);
        $this->assertSame(VisitEventType::Completed, $visit->events->last()->event);
    }

    public function test_a_visit_that_was_sent_on_can_still_be_completed_where_it_ended_up(): void
    {
        $visit = $this->inService($this->consultation);
        $this->actingAs($this->doctor)->post(route('queue.transfer', [$visit, $this->laboratory]));
        $this->actingAs($this->labTech)->post(route('queue.call', $visit));
        $this->actingAs($this->labTech)->post(route('queue.start', $visit));

        $this->actingAs($this->labTech)->post(route('queue.complete', $visit))->assertSessionHasNoErrors();

        $this->assertSame(VisitStatus::Completed, $visit->fresh()->status);
    }

    /**
     * @return array<string, array{VisitStatus}>
     */
    public static function statusesNotReadyToBeSentOn(): array
    {
        return [
            'waiting' => [VisitStatus::Waiting],
            'called' => [VisitStatus::Called],
            'completed' => [VisitStatus::Completed],
            'cancelled' => [VisitStatus::Cancelled],
        ];
    }

    #[DataProvider('statusesNotReadyToBeSentOn')]
    public function test_a_patient_who_has_not_been_seen_cannot_be_sent_on_and_is_told_why(VisitStatus $status): void
    {
        $visit = $this->inService($this->consultation);
        $visit->update(['status' => $status, 'queue_number' => 27]);

        $this->actingAs($this->doctor)
            ->post(route('queue.transfer', [$visit, $this->laboratory]))
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasErrors('queue');

        $fresh = $visit->fresh();
        $this->assertSame($this->consultation->id, $fresh->department_id);
        $this->assertSame($status, $fresh->status);
        $this->assertNull($fresh->department_queue_number);
        $this->assertDatabaseCount('visit_events', 0);
        $this->assertDatabaseCount('queue_counters', 0);
    }

    public function test_the_refusal_for_a_patient_not_yet_seen_reads_clearly_on_the_page(): void
    {
        $visit = $this->inService($this->consultation);
        $visit->update(['status' => VisitStatus::Called]);

        $this->actingAs($this->doctor)
            ->followingRedirects()
            ->post(route('queue.transfer', [$visit, $this->laboratory]))
            ->assertSeeText("#27 is already Called, so that can't be done. The queue shows the latest status.");
    }

    public function test_a_patient_cannot_be_sent_to_the_department_they_are_already_in(): void
    {
        $visit = $this->inService($this->consultation);

        $this->actingAs($this->doctor)
            ->followingRedirects()
            ->post(route('queue.transfer', [$visit, $this->consultation]))
            ->assertSeeText('#27 is already in Consultation.');

        $this->assertDatabaseCount('visit_events', 0);
    }

    public function test_a_patient_cannot_be_sent_to_a_department_that_is_not_active(): void
    {
        $closed = Department::factory()->for($this->facility)->inactive()->create(['name' => 'Closed Ward']);
        $visit = $this->inService($this->consultation);

        $this->actingAs($this->doctor)
            ->followingRedirects()
            ->post(route('queue.transfer', [$visit, $closed]))
            ->assertSeeText("Closed Ward isn't taking patients right now, so nobody can be sent there.");

        $this->assertSame($this->consultation->id, $visit->fresh()->department_id);
    }

    public function test_another_facilitys_department_does_not_exist_as_a_destination(): void
    {
        $foreign = Department::factory()->for(Facility::factory())->create();
        $visit = $this->inService($this->consultation);

        $this->actingAs($this->doctor)->post(route('queue.transfer', [$visit, $foreign]))->assertNotFound();
        $this->actingAs($this->admin)->post(route('queue.transfer', [$visit, $foreign]))->assertNotFound();

        $this->assertSame($this->consultation->id, $visit->fresh()->department_id);
        $this->assertDatabaseCount('visit_events', 0);
    }

    public function test_a_department_that_does_not_exist_is_a_404(): void
    {
        $visit = $this->inService($this->consultation);

        $this->actingAs($this->doctor)->post(route('queue.transfer', [$visit, 999999]))->assertNotFound();
    }

    public function test_another_facilitys_visit_does_not_exist_for_anyone_here(): void
    {
        $otherFacility = Facility::factory()->create();
        $theirs = Visit::factory()->for($otherFacility)->create([
            'department_id' => Department::factory()->for($otherFacility)->create()->id,
            'status' => VisitStatus::InService,
        ]);

        $this->actingAs($this->admin)->post(route('queue.transfer', [$theirs, $this->laboratory]))->assertNotFound();
        $this->assertSame(VisitStatus::InService, $theirs->fresh()->status);
    }

    public function test_staff_of_another_department_cannot_send_a_patient_on(): void
    {
        $visit = $this->inService($this->consultation);

        $this->actingAs($this->labTech)->post(route('queue.transfer', [$visit, $this->pharmacy]))->assertForbidden();
        $this->actingAs($this->receptionist)->post(route('queue.transfer', [$visit, $this->pharmacy]))->assertForbidden();

        $this->assertSame($this->consultation->id, $visit->fresh()->department_id);
        $this->assertDatabaseCount('visit_events', 0);
    }

    public function test_a_receptionist_can_send_on_a_patient_from_their_own_department(): void
    {
        $visit = $this->inService($this->reception);

        $this->actingAs($this->receptionist)->post(route('queue.transfer', [$visit, $this->consultation]))->assertSessionHasNoErrors();

        $this->assertSame($this->consultation->id, $visit->fresh()->department_id);
    }

    public function test_an_admin_can_send_a_patient_on_from_any_department_and_returns_to_the_tab_they_were_on(): void
    {
        $visit = $this->inService($this->laboratory);

        $this->actingAs($this->admin)
            ->post(route('queue.transfer', [$visit, $this->pharmacy]))
            ->assertRedirect(route('queue.index', ['department' => $this->laboratory->id]));

        $this->assertSame($this->pharmacy->id, $visit->fresh()->department_id);
        $this->assertSame($this->admin->id, $visit->events->sole()->user_id);
    }

    public function test_pressing_send_twice_only_sends_them_once_and_only_uses_one_number(): void
    {
        $visit = $this->inService($this->consultation);

        $this->actingAs($this->doctor)->post(route('queue.transfer', [$visit, $this->laboratory]))->assertSessionHasNoErrors();
        // The second press is by the lab now, on a patient who is waiting there, not in service.
        $this->actingAs($this->labTech)->post(route('queue.transfer', [$visit, $this->pharmacy]))->assertSessionHasErrors('queue');

        $this->assertCount(1, $visit->events);
        $this->assertSame(1, QueueCounter::where('department_id', $this->laboratory->id)->sole()->last_number);
        $this->assertNull(QueueCounter::where('department_id', $this->pharmacy->id)->first());
    }

    public function test_the_transfer_only_answers_to_post(): void
    {
        $visit = $this->inService($this->consultation);

        $this->actingAs($this->doctor)->get("/queue/{$visit->id}/transfer/{$this->laboratory->id}")->assertStatus(405);
    }

    public function test_a_system_admin_and_a_guest_cannot_transfer(): void
    {
        $visit = $this->inService($this->consultation);

        $this->actingAs(User::factory()->systemAdmin()->create())
            ->post(route('queue.transfer', [$visit, $this->laboratory]))
            ->assertForbidden();

        auth()->logout();
        $this->post(route('queue.transfer', [$visit, $this->laboratory]))->assertRedirect(route('login'));

        $this->assertSame($this->consultation->id, $visit->fresh()->department_id);
    }
}
