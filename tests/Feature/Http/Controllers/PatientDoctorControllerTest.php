<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DepartmentType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientDoctorControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $receptionist;

    private Department $consultation;

    private Department $laboratory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->receptionist = User::factory()->for($this->facility)->receptionist()->create();
        $this->consultation = Department::factory()->for($this->facility)->assigningDoctors()->create(['type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Laboratory]);
    }

    private function doctor(string $name, bool $onDuty = true): User
    {
        $factory = User::factory()->for($this->facility)->doctor();

        return ($onDuty ? $factory->onDuty() : $factory)->create(['name' => $name, 'department_id' => $this->consultation->id]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function ask(array $query)
    {
        return $this->actingAs($this->receptionist)->getJson(route('patients.doctors', $query));
    }

    public function test_lists_the_doctors_on_duty_shortest_line_first_with_only_the_first_recommended(): void
    {
        $busy = $this->doctor('Dr. Otieno');
        Visit::factory()->inLineOf($busy, 1)->count(7)->create(['status' => VisitStatus::Waiting]);
        Visit::factory()->inLineOf($quiet = $this->doctor('Dr. Wanjiku'), 1)->count(2)->create();
        $this->doctor('Dr. Away', onDuty: false);

        $this->ask(['department_id' => $this->consultation->id])
            ->assertOk()
            ->assertJsonPath('requires_doctor', true)
            ->assertJsonCount(2, 'doctors')
            ->assertJsonPath('doctors.0', [
                'id' => $quiet->id,
                'name' => 'Dr. Wanjiku',
                'specialty' => null,
                'waiting' => 2,
                'estimate' => '15–20 minutes',
                'recommended' => true,
            ])
            ->assertJsonPath('doctors.1.name', 'Dr. Otieno')
            ->assertJsonPath('doctors.1.recommended', false);
    }

    public function test_offers_the_departments_services_and_narrows_the_doctors_to_the_one_chosen(): void
    {
        $paediatrics = Service::factory()->for($this->consultation)->create(['name' => 'Pediatrics']);
        $general = Service::factory()->for($this->consultation)->create(['name' => 'General Medicine']);
        $this->doctor('Dr. Child')->update(['service_id' => $paediatrics->id]);
        $this->doctor('Dr. General')->update(['service_id' => $general->id]);

        $this->ask(['department_id' => $this->consultation->id, 'service_id' => $paediatrics->id])
            ->assertOk()
            ->assertJsonPath('services', [['id' => $general->id, 'name' => 'General Medicine'], ['id' => $paediatrics->id, 'name' => 'Pediatrics']])
            ->assertJsonCount(1, 'doctors')
            ->assertJsonPath('doctors.0.name', 'Dr. Child')
            ->assertJsonPath('doctors.0.specialty', 'Pediatrics');
    }

    public function test_a_service_that_is_not_the_departments_narrows_nothing(): void
    {
        $this->doctor('Dr. Wanjiku');
        $labService = Service::factory()->for($this->laboratory)->create();

        $this->ask(['department_id' => $this->consultation->id, 'service_id' => $labService->id])
            ->assertOk()
            ->assertJsonCount(1, 'doctors');
    }

    public function test_a_department_that_does_not_assign_doctors_has_no_doctor_list(): void
    {
        $this->doctor('Dr. Wanjiku');

        $this->ask(['department_id' => $this->laboratory->id])
            ->assertOk()
            ->assertExactJson(['requires_doctor' => false, 'services' => [], 'doctors' => []]);
    }

    public function test_says_when_no_doctor_is_on_duty(): void
    {
        $this->doctor('Dr. Away', onDuty: false);

        $this->ask(['department_id' => $this->consultation->id])
            ->assertOk()
            ->assertJsonPath('requires_doctor', true)
            ->assertJsonPath('doctors', []);
    }

    public function test_another_facilitys_department_is_refused_and_leaks_nothing(): void
    {
        $theirs = Department::factory()->for(Facility::factory()->create())->assigningDoctors()->create();

        $this->ask(['department_id' => $theirs->id])->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->ask([])->assertUnprocessable()->assertJsonValidationErrors('department_id');
    }

    public function test_doctors_and_nurses_cannot_ask_and_guests_are_sent_to_log_in(): void
    {
        $query = ['department_id' => $this->consultation->id];

        $this->actingAs(User::factory()->for($this->facility)->doctor()->create())->getJson(route('patients.doctors', $query))->assertForbidden();
        $this->actingAs(User::factory()->for($this->facility)->nurse()->create())->getJson(route('patients.doctors', $query))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson(route('patients.doctors', $query))->assertUnauthorized();
    }
}
