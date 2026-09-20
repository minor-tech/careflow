<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Enums\DepartmentType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $admin;

    private Department $consultation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->admin = User::factory()->for($this->facility)->create();
        $this->consultation = Department::factory()->for($this->facility)->assigningDoctors()->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
    }

    public function test_the_department_page_lists_its_services_with_how_many_doctors_have_each_as_a_specialty(): void
    {
        $paediatrics = Service::factory()->for($this->consultation)->create(['name' => 'Pediatrics']);
        Service::factory()->for($this->consultation)->create(['name' => 'Dermatology']);
        User::factory()->for($this->facility)->doctor()->count(2)->create(['department_id' => $this->consultation->id, 'service_id' => $paediatrics->id]);
        Service::factory()->for(Department::factory()->for($this->facility)->create())->create(['name' => 'Blood tests']);

        $this->actingAs($this->admin)
            ->get(route('departments.edit', $this->consultation))
            ->assertOk()
            ->assertSeeTextInOrder(['Dermatology', '0 doctors', 'Pediatrics', '2 doctors'])
            ->assertDontSeeText('Blood tests');
    }

    public function test_an_admin_adds_a_service_to_a_department(): void
    {
        $this->actingAs($this->admin)
            ->post(route('departments.services.store', $this->consultation), ['name' => 'General Medicine'])
            ->assertRedirect(route('departments.edit', $this->consultation))
            ->assertSessionHas('success', 'General Medicine was added.');

        $this->assertSame(['General Medicine'], $this->consultation->services()->pluck('name')->all());
    }

    public function test_a_name_is_required_and_cannot_repeat_within_a_department_but_can_in_another(): void
    {
        Service::factory()->for($this->consultation)->create(['name' => 'Pediatrics']);
        $other = Department::factory()->for($this->facility)->create();

        $this->actingAs($this->admin)->post(route('departments.services.store', $this->consultation), ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->post(route('departments.services.store', $this->consultation), ['name' => 'Pediatrics'])
            ->assertSessionHasErrors(['name' => 'This department already has a service with this name.']);
        $this->actingAs($this->admin)->post(route('departments.services.store', $other), ['name' => 'Pediatrics'])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->consultation->services()->count());
    }

    public function test_removing_a_service_leaves_its_doctors_with_no_specialty(): void
    {
        $service = Service::factory()->for($this->consultation)->create(['name' => 'Dermatology']);
        $doctor = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id, 'service_id' => $service->id]);

        $this->actingAs($this->admin)
            ->delete(route('services.destroy', $service))
            ->assertRedirect(route('departments.edit', $this->consultation))
            ->assertSessionHas('success', 'Dermatology was removed.');

        $this->assertModelMissing($service);
        $this->assertNull($doctor->fresh()->service_id);
    }

    public function test_a_service_patients_were_registered_for_cannot_be_removed(): void
    {
        $service = Service::factory()->for($this->consultation)->create(['name' => 'Dermatology']);
        Visit::factory()->for($this->facility)->create(['department_id' => $this->consultation->id, 'service_id' => $service->id]);

        $this->actingAs($this->admin)
            ->delete(route('services.destroy', $service))
            ->assertSessionHasErrors(['service' => 'Dermatology has patients registered for it, so it can\'t be removed.']);

        $this->assertModelExists($service);
    }

    public function test_such_a_service_also_stops_its_department_being_removed_instead_of_failing_on_the_database(): void
    {
        $blank = Department::factory()->for($this->facility)->create(['name' => 'Quiet Ward']);
        $service = Service::factory()->for($blank)->create();
        Visit::factory()->for($this->facility)->create(['department_id' => $this->consultation->id, 'service_id' => $service->id]);

        $this->actingAs($this->admin)
            ->delete(route('departments.destroy', $blank))
            ->assertSessionHasErrors('department');

        $this->assertModelExists($blank);
    }

    public function test_another_facilitys_admin_gets_not_found_and_nothing_changes(): void
    {
        $outsider = User::factory()->for(Facility::factory()->create())->create();
        $service = Service::factory()->for($this->consultation)->create();

        $this->actingAs($outsider)->post(route('departments.services.store', $this->consultation), ['name' => 'Sneaky'])->assertNotFound();
        $this->actingAs($outsider)->delete(route('services.destroy', $service))->assertNotFound();

        $this->assertSame(1, Service::count());
    }

    public function test_only_admins_can_manage_services(): void
    {
        $service = Service::factory()->for($this->consultation)->create();

        foreach ([User::factory()->for($this->facility)->doctor()->create(), User::factory()->for($this->facility)->receptionist()->create()] as $staff) {
            $this->actingAs($staff)->post(route('departments.services.store', $this->consultation), ['name' => 'Sneaky'])->assertForbidden();
            $this->actingAs($staff)->delete(route('services.destroy', $service))->assertForbidden();
        }

        $this->assertSame(1, Service::count());
    }
}
