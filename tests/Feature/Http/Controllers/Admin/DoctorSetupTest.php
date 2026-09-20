<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Enums\DepartmentType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The admin's side of doctor assignment: which departments give each patient
 * their own doctor, and each doctor's specialty.
 */
class DoctorSetupTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $admin;

    private Department $consultation;

    private Department $laboratory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->admin = User::factory()->for($this->facility)->create();
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
    }

    public function test_a_new_department_gives_each_patient_their_own_doctor_only_when_ticked(): void
    {
        $this->actingAs($this->admin)->post(route('departments.store'), ['name' => 'Clinic', 'type' => 'consultation', 'is_active' => 1, 'requires_doctor_assignment' => 1]);
        $this->actingAs($this->admin)->post(route('departments.store'), ['name' => 'Store', 'type' => 'other', 'is_active' => 1, 'requires_doctor_assignment' => 0]);
        $this->actingAs($this->admin)->post(route('departments.store'), ['name' => 'Kitchen', 'type' => 'other', 'is_active' => 1]);

        $this->assertTrue(Department::where('name', 'Clinic')->sole()->requires_doctor_assignment);
        $this->assertFalse(Department::where('name', 'Store')->sole()->requires_doctor_assignment);
        $this->assertFalse(Department::where('name', 'Kitchen')->sole()->requires_doctor_assignment);
    }

    public function test_the_create_form_ticks_the_box_for_a_consultation_type_department_as_you_pick_it(): void
    {
        $this->actingAs($this->admin)
            ->get(route('departments.create'))
            ->assertOk()
            ->assertSee('name="requires_doctor_assignment"', false)
            ->assertSee("if (type === 'consultation') assign = true", false);
    }

    public function test_an_existing_department_can_be_switched_on_and_off_and_its_edit_form_shows_which(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('departments.update', $this->consultation), ['name' => 'Consultation', 'type' => 'consultation', 'is_active' => 1, 'requires_doctor_assignment' => 1])
            ->assertRedirect(route('departments.index'));

        $this->assertTrue($this->consultation->fresh()->requires_doctor_assignment);

        $this->actingAs($this->admin)
            ->get(route('departments.edit', $this->consultation))
            ->assertSee('name="requires_doctor_assignment" value="1"', false)
            ->assertSee('checked', false);

        $this->actingAs($this->admin)
            ->patch(route('departments.update', $this->consultation), ['name' => 'Consultation', 'type' => 'consultation', 'is_active' => 1, 'requires_doctor_assignment' => 0]);

        $this->assertFalse($this->consultation->fresh()->requires_doctor_assignment);
    }

    public function test_the_departments_list_says_which_departments_give_each_patient_their_own_doctor(): void
    {
        $this->consultation->update(['requires_doctor_assignment' => true]);

        $this->actingAs($this->admin)
            ->get(route('departments.index'))
            ->assertSeeText('each patient has their own doctor', false);
    }

    public function test_a_doctor_is_created_with_a_specialty_from_their_own_departments_services(): void
    {
        Notification::fake();
        $paediatrics = Service::factory()->for($this->consultation)->create(['name' => 'Pediatrics']);

        $this->actingAs($this->admin)->post(route('staff.store'), [
            'name' => 'Otieno Odhiambo', 'email' => 'otieno@upendo.test', 'phone' => '0733 222 333',
            'role' => 'doctor', 'department_id' => $this->consultation->id, 'service_id' => $paediatrics->id,
        ])->assertSessionHasNoErrors();

        $doctor = User::where('email', 'otieno@upendo.test')->sole();
        $this->assertSame($paediatrics->id, $doctor->service_id);
        $this->assertFalse($doctor->isOnDuty(), 'Nobody starts on duty: they switch themselves on.');
    }

    public function test_a_specialty_from_another_department_is_refused(): void
    {
        Notification::fake();
        $labService = Service::factory()->for($this->laboratory)->create();

        $this->actingAs($this->admin)->post(route('staff.store'), [
            'name' => 'Otieno Odhiambo', 'email' => 'otieno@upendo.test', 'phone' => '0733 222 333',
            'role' => 'doctor', 'department_id' => $this->consultation->id, 'service_id' => $labService->id,
        ])->assertSessionHasErrors(['service_id' => 'Choose one of the services of the department this person works in.']);

        $this->assertDatabaseMissing('users', ['email' => 'otieno@upendo.test']);
    }

    public function test_only_a_doctor_keeps_a_specialty(): void
    {
        Notification::fake();
        $paediatrics = Service::factory()->for($this->consultation)->create(['name' => 'Pediatrics']);

        $this->actingAs($this->admin)->post(route('staff.store'), [
            'name' => 'Nia Nurse', 'email' => 'nia@upendo.test', 'phone' => '0733 222 334',
            'role' => 'nurse', 'department_id' => $this->consultation->id, 'service_id' => $paediatrics->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull(User::where('email', 'nia@upendo.test')->sole()->service_id);
    }

    public function test_changing_a_doctors_specialty_and_moving_them_out_of_doctoring_clears_what_no_longer_applies(): void
    {
        $paediatrics = Service::factory()->for($this->consultation)->create(['name' => 'Pediatrics']);
        $doctor = User::factory()->for($this->facility)->doctor()->onDuty()->create(['department_id' => $this->consultation->id]);
        $attributes = ['name' => $doctor->name, 'phone' => '0700 111 222', 'department_id' => $this->consultation->id, 'status' => 'active'];

        $this->actingAs($this->admin)->patch(route('staff.update', $doctor), [...$attributes, 'role' => 'doctor', 'service_id' => $paediatrics->id])->assertSessionHasNoErrors();
        $this->assertSame($paediatrics->id, $doctor->fresh()->service_id);
        $this->assertTrue($doctor->fresh()->isOnDuty(), 'Editing a doctor does not take them off duty.');

        $this->actingAs($this->admin)->patch(route('staff.update', $doctor), [...$attributes, 'role' => 'nurse', 'service_id' => $paediatrics->id])->assertSessionHasNoErrors();
        $this->assertNull($doctor->fresh()->service_id);
        $this->assertFalse($doctor->fresh()->isOnDuty(), 'A nurse cannot stay on duty as a doctor.');
    }

    public function test_the_staff_forms_carry_each_departments_services_for_the_specialty_choice(): void
    {
        Service::factory()->for($this->consultation)->create(['name' => 'Pediatrics']);
        Service::factory()->for(Department::factory()->for(Facility::factory()->create())->create())->create(['name' => 'Someone Elses Service']);

        $this->actingAs($this->admin)
            ->get(route('staff.create'))
            ->assertOk()
            ->assertSee('Pediatrics')
            ->assertDontSee('Someone Elses Service')
            ->assertSee('name="service_id"', false);
    }
}
