<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\RegisterPatientVisit;
use App\Enums\DepartmentType;
use App\Enums\UserStatus;
use App\Enums\VisitEventType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\QueueCounter;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PatientVisitDoctorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $receptionist;

    private Department $reception;

    private Department $consultation;

    private Department $laboratory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->receptionist = User::factory()->for($this->facility)->receptionist()->create();
        $this->reception = Department::factory()->for($this->facility)->create(['name' => 'Reception', 'type' => DepartmentType::Reception]);
        $this->consultation = Department::factory()->for($this->facility)->assigningDoctors()->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
    }

    private function doctor(string $name = 'Dr. Wanjiku', bool $onDuty = true): User
    {
        $factory = User::factory()->for($this->facility)->doctor();

        return ($onDuty ? $factory->onDuty() : $factory)->create(['name' => $name, 'department_id' => $this->consultation->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function register(array $overrides = [])
    {
        return $this->actingAs($this->receptionist)->post(route('patients.register.store'), [
            'phone' => '0712 345 678',
            'name' => 'Julius Maina',
            'department_id' => $this->consultation->id,
            ...$overrides,
        ]);
    }

    public function test_registering_into_a_department_that_assigns_doctors_needs_a_doctor(): void
    {
        $this->doctor();

        $this->register()
            ->assertRedirect()
            ->assertSessionHasErrors(['doctor_id' => 'Choose a doctor for this patient. If none are listed, no doctor is on duty in this department right now.']);

        $this->assertDatabaseCount('visits', 0);
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_the_chosen_doctor_gets_the_patient_in_their_own_line_and_the_audit_log_says_so(): void
    {
        $general = Service::factory()->for($this->consultation)->create(['name' => 'General Medicine']);
        $doctor = $this->doctor();
        $doctor->update(['service_id' => $general->id]);

        $response = $this->register(['doctor_id' => $doctor->id, 'service_id' => $general->id]);

        $visit = Visit::sole();
        $response->assertRedirect(route('visits.confirmation', $visit));

        $this->assertSame($this->consultation->id, $visit->department_id);
        $this->assertSame($doctor->id, $visit->assigned_doctor_id);
        $this->assertSame(1, $visit->doctor_queue_number);
        $this->assertSame($general->id, $visit->service_id);
        $this->assertSame(1, $visit->queue_number, 'The facility-wide number is still given at registration.');

        $log = $visit->events;
        $this->assertSame([VisitEventType::Registered, VisitEventType::DoctorAssigned], $log->pluck('event')->all());
        $this->assertSame(['doctor_id' => $doctor->id], $log->last()->meta);
        $this->assertSame($this->receptionist->id, $log->last()->user_id);
        $this->assertSame($this->consultation->id, $log->last()->department_id);
    }

    public function test_two_doctors_in_the_same_department_get_independent_sequential_queue_numbers(): void
    {
        $wanjiku = $this->doctor('Dr. Wanjiku');
        $kamau = $this->doctor('Dr. Kamau');

        foreach ([[$wanjiku, '0711 000 001'], [$wanjiku, '0711 000 002'], [$kamau, '0711 000 003'], [$wanjiku, '0711 000 004'], [$kamau, '0711 000 005']] as [$doctor, $phone]) {
            $this->register(['doctor_id' => $doctor->id, 'phone' => $phone]);
        }

        $numbers = Visit::orderBy('id')->get()->map(fn (Visit $visit) => [$visit->assigned_doctor_id === $wanjiku->id ? 'W' : 'K', $visit->doctor_queue_number, $visit->queue_number])->all();

        $this->assertSame([['W', 1, 1], ['W', 2, 2], ['K', 1, 3], ['W', 3, 4], ['K', 2, 5]], $numbers);
    }

    public function test_the_receptionist_can_override_the_recommendation_with_any_other_doctor_on_duty(): void
    {
        $shortLine = $this->doctor('Dr. Wanjiku');
        $longLine = $this->doctor('Dr. Kamau');
        Visit::factory()->inLineOf($longLine, 1)->count(5)->create();

        $this->register(['doctor_id' => $longLine->id])->assertSessionHasNoErrors();

        $this->assertSame($longLine->id, Visit::latest('id')->first()->assigned_doctor_id);
        $this->assertNotSame($shortLine->id, Visit::latest('id')->first()->assigned_doctor_id);
    }

    public function test_a_doctor_who_is_off_duty_cannot_be_chosen(): void
    {
        $this->doctor();
        $away = $this->doctor('Dr. Away', onDuty: false);

        $this->register(['doctor_id' => $away->id])
            ->assertSessionHasErrors(['doctor_id' => 'That doctor isn\'t on duty for this department and service. Choose one from the list.']);

        $this->assertDatabaseCount('visits', 0);
    }

    public function test_a_doctor_who_is_suspended_or_from_another_department_or_facility_cannot_be_chosen(): void
    {
        $suspended = $this->doctor('Dr. Suspended');
        $suspended->update(['status' => UserStatus::Suspended]);
        $elsewhere = User::factory()->for($this->facility)->doctor()->onDuty()->create(['department_id' => $this->laboratory->id]);
        $outsider = User::factory()->for(Facility::factory()->create())->doctor()->onDuty()->create(['department_id' => $this->consultation->id]);

        foreach ([$suspended, $elsewhere, $outsider] as $doctor) {
            $this->register(['doctor_id' => $doctor->id])->assertSessionHasErrors('doctor_id');
        }

        $this->assertDatabaseCount('visits', 0);
    }

    public function test_the_doctor_must_have_the_specialty_the_patient_came_for(): void
    {
        $paediatrics = Service::factory()->for($this->consultation)->create(['name' => 'Pediatrics']);
        $general = Service::factory()->for($this->consultation)->create(['name' => 'General Medicine']);
        $child = $this->doctor('Dr. Child');
        $child->update(['service_id' => $paediatrics->id]);
        $generalist = $this->doctor('Dr. General');
        $generalist->update(['service_id' => $general->id]);

        $this->register(['doctor_id' => $generalist->id, 'service_id' => $paediatrics->id])->assertSessionHasErrors('doctor_id');
        $this->register(['doctor_id' => $child->id, 'service_id' => $paediatrics->id])->assertSessionHasNoErrors();

        $this->assertSame($child->id, Visit::sole()->assigned_doctor_id);
    }

    public function test_a_service_of_another_department_is_refused(): void
    {
        $doctor = $this->doctor();
        $labService = Service::factory()->for($this->laboratory)->create(['name' => 'Blood tests']);

        $this->register(['doctor_id' => $doctor->id, 'service_id' => $labService->id])
            ->assertSessionHasErrors(['service_id' => 'Choose one of this department\'s services.']);
    }

    public function test_a_department_that_does_not_assign_doctors_registers_exactly_as_before(): void
    {
        $doctor = $this->doctor();

        foreach ([$this->laboratory, $this->reception] as $department) {
            $this->register(['department_id' => $department->id, 'phone' => '07'.random_int(10000000, 99999999)])->assertSessionHasNoErrors();
        }

        $this->register(['department_id' => '', 'phone' => '0799 000 111', 'doctor_id' => $doctor->id])->assertSessionHasNoErrors();

        foreach (Visit::all() as $visit) {
            $this->assertNull($visit->assigned_doctor_id, 'A doctor sent to a department that has no doctor lines is ignored.');
            $this->assertNull($visit->doctor_queue_number);
        }

        $this->assertNotContains(VisitEventType::DoctorAssigned, Visit::all()->flatMap->events->pluck('event')->all());
        $this->assertDatabaseMissing('queue_counters', ['doctor_id' => $doctor->id]);
    }

    public function test_the_confirmation_names_the_doctor_and_the_place_in_their_line(): void
    {
        $doctor = $this->doctor('Wanjiku Mwangi');

        $this->register(['doctor_id' => $doctor->id]);

        $this->get(route('visits.confirmation', Visit::sole()))
            ->assertOk()
            ->assertSeeText('Assigned to Dr. Wanjiku Mwangi')
            ->assertSeeText('C-1');
    }

    public function test_the_action_refuses_to_register_anyone_into_a_doctors_department_without_a_doctor(): void
    {
        try {
            app(RegisterPatientVisit::class)->handle($this->receptionist, [
                'phone' => '+254712345678',
                'name' => 'Julius Maina',
                'department_id' => $this->consultation->id,
            ]);

            $this->fail('A patient was registered with no doctor.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('choose one before registering', $exception->getMessage());
        }

        $this->assertSame(0, Visit::count() + Patient::count() + QueueCounter::count(), 'Nothing is left behind, not even a used-up queue number.');
    }
}
