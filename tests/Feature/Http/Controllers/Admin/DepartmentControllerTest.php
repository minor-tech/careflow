<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Enums\DepartmentType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->admin = User::factory()->for($this->facility)->create();
    }

    public function test_index_lists_this_facilitys_departments_with_staff_counts_and_no_one_elses(): void
    {
        $lab = Department::factory()->for($this->facility)->create(['name' => 'Our Lab', 'type' => DepartmentType::Laboratory]);
        User::factory()->for($this->facility)->count(3)->nurse()->create(['department_id' => $lab->id]);
        Department::factory()->for(Facility::factory())->create(['name' => 'Their Pharmacy']);

        $this->actingAs($this->admin)
            ->get(route('departments.index'))
            ->assertOk()
            ->assertSeeText('Our Lab')
            ->assertSeeInOrder(['Our Lab', '3', 'staff'])
            ->assertDontSeeText('Their Pharmacy');
    }

    public function test_inactive_departments_are_marked_on_the_list(): void
    {
        Department::factory()->for($this->facility)->inactive()->create(['name' => 'Old Ward']);

        $this->actingAs($this->admin)
            ->get(route('departments.index'))
            ->assertSeeText('Inactive');
    }

    public function test_store_adds_a_department_to_the_admins_own_facility(): void
    {
        $other = Facility::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('departments.store'), [
                'name' => 'Dental Clinic',
                'type' => 'dental',
                'is_active' => '1',
                'facility_id' => $other->id,
            ])
            ->assertRedirect(route('departments.index'));

        $department = Department::where('name', 'Dental Clinic')->sole();

        $this->assertSame($this->facility->id, $department->facility_id);
        $this->assertSame(DepartmentType::Dental, $department->type);
        $this->assertTrue($department->is_active);
    }

    public function test_store_rejects_a_missing_name_and_an_unknown_type(): void
    {
        $this->actingAs($this->admin)
            ->post(route('departments.store'), ['name' => '', 'type' => 'casino'])
            ->assertSessionHasErrors(['name', 'type']);

        $this->assertDatabaseCount('departments', 0);
    }

    public function test_a_name_can_only_be_used_once_per_facility_but_other_facilities_may_reuse_it(): void
    {
        Department::factory()->for($this->facility)->create(['name' => 'Pharmacy']);
        Department::factory()->for(Facility::factory())->create(['name' => 'Triage']);

        $this->actingAs($this->admin)
            ->post(route('departments.store'), ['name' => 'Pharmacy', 'type' => 'pharmacy'])
            ->assertSessionHasErrors(['name' => 'You already have a department with this name.']);

        $this->actingAs($this->admin)
            ->post(route('departments.store'), ['name' => 'Triage', 'type' => 'other'])
            ->assertSessionHasNoErrors();
    }

    public function test_update_renames_and_deactivates_and_may_keep_its_own_name(): void
    {
        $department = Department::factory()->for($this->facility)->create(['name' => 'Pharmacy', 'type' => DepartmentType::Pharmacy]);

        $this->actingAs($this->admin)->get(route('departments.edit', $department))->assertOk()->assertSee('Pharmacy');

        $this->actingAs($this->admin)
            ->patch(route('departments.update', $department), ['name' => 'Pharmacy', 'type' => 'pharmacy', 'is_active' => '0'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('departments.index'));

        $this->assertFalse($department->fresh()->is_active);

        $this->actingAs($this->admin)
            ->patch(route('departments.update', $department), ['name' => 'Main Pharmacy', 'type' => 'pharmacy', 'is_active' => '1']);

        $this->assertSame('Main Pharmacy', $department->fresh()->name);
        $this->assertTrue($department->fresh()->is_active);
    }

    public function test_destroy_removes_an_empty_department(): void
    {
        $department = Department::factory()->for($this->facility)->create();

        $this->actingAs($this->admin)
            ->delete(route('departments.destroy', $department))
            ->assertRedirect(route('departments.index'));

        $this->assertModelMissing($department);
    }

    public function test_destroy_is_refused_while_staff_are_assigned(): void
    {
        $department = Department::factory()->for($this->facility)->create(['name' => 'Consultation']);
        User::factory()->for($this->facility)->doctor()->create(['department_id' => $department->id]);

        $this->actingAs($this->admin)
            ->from(route('departments.index'))
            ->delete(route('departments.destroy', $department))
            ->assertRedirect(route('departments.index'))
            ->assertSessionHasErrors('department');

        $this->assertModelExists($department);
    }

    public function test_destroy_is_refused_while_the_department_has_visit_history(): void
    {
        $department = Department::factory()->for($this->facility)->create(['name' => 'Laboratory']);
        Visit::factory()->for($this->facility)->create(['department_id' => $department->id]);

        $this->actingAs($this->admin)
            ->from(route('departments.index'))
            ->delete(route('departments.destroy', $department))
            ->assertRedirect(route('departments.index'))
            ->assertSessionHasErrors('department');

        $this->assertModelExists($department);
        $this->assertSame($department->id, Visit::sole()->department_id);
    }

    public function test_destroy_is_refused_while_the_audit_log_still_points_at_the_department(): void
    {
        $department = Department::factory()->for($this->facility)->create(['name' => 'Old Triage']);
        $visit = Visit::factory()->for($this->facility)->create(['department_id' => null]);
        VisitEvent::factory()->for($visit)->create(['department_id' => $department->id]);

        $this->actingAs($this->admin)
            ->from(route('departments.index'))
            ->delete(route('departments.destroy', $department))
            ->assertSessionHasErrors('department');

        $this->assertModelExists($department);
    }

    public function test_another_facilitys_departments_are_invisible_to_edit_update_and_destroy(): void
    {
        $outsider = Department::factory()->for(Facility::factory())->create(['name' => 'Outsider Ward']);

        $this->actingAs($this->admin)->get(route('departments.edit', $outsider))->assertNotFound();
        $this->actingAs($this->admin)
            ->patch(route('departments.update', $outsider), ['name' => 'Hijacked', 'type' => 'other', 'is_active' => '1'])
            ->assertNotFound();
        $this->actingAs($this->admin)->delete(route('departments.destroy', $outsider))->assertNotFound();

        $this->assertSame('Outsider Ward', $outsider->fresh()->name);
    }

    public function test_a_system_admin_cannot_manage_a_facilitys_departments(): void
    {
        $this->actingAs(User::factory()->systemAdmin()->create())
            ->get(route('departments.index'))
            ->assertForbidden();
    }
}
