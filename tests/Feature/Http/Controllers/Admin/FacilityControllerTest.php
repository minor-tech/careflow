<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_the_admins_facility_with_its_counts_and_status(): void
    {
        $facility = Facility::factory()->create([
            'name' => 'Upendo Health Centre',
            'county' => 'Kiambu',
            'operating_days' => ['mon', 'sat'],
            'opens_at' => '08:00:00',
            'closes_at' => '17:30:00',
        ]);
        $admin = User::factory()->for($facility)->create();
        User::factory()->for($facility)->count(2)->nurse()->create();
        Department::factory()->for($facility)->count(3)->create();

        $this->actingAs($admin)
            ->get(route('admin.facility'))
            ->assertOk()
            ->assertSeeText('Upendo Health Centre')
            ->assertSeeText('Kiambu')
            ->assertSeeText('Mon, Sat')
            ->assertSeeText('8:00 AM to 5:30 PM')
            ->assertSeeText('Active')
            ->assertSeeInOrder(['3', 'Staff accounts', '3', 'Departments']);
    }

    public function test_shows_that_a_24_hour_facility_is_always_open(): void
    {
        $admin = User::factory()->for(Facility::factory()->open24Hours())->create();

        $this->actingAs($admin)
            ->get(route('admin.facility'))
            ->assertSeeText('Open 24 hours');
    }

    public function test_never_shows_another_facilitys_details(): void
    {
        Facility::factory()->create(['name' => 'Somebody Elses Clinic']);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.facility'))
            ->assertDontSeeText('Somebody Elses Clinic');
    }

    public function test_a_system_admin_has_no_facility_page(): void
    {
        $this->actingAs(User::factory()->systemAdmin()->create())
            ->get(route('admin.facility'))
            ->assertForbidden();
    }

    public function test_lists_the_facilitys_departments_with_their_staff_counts_and_flags_inactive_ones(): void
    {
        $facility = Facility::factory()->create();
        $admin = User::factory()->for($facility)->create();
        $reception = Department::factory()->for($facility)->create(['name' => 'Reception']);
        Department::factory()->for($facility)->inactive()->create(['name' => 'Old Ward']);
        User::factory()->for($facility)->receptionist()->count(2)->create(['department_id' => $reception->id]);
        Department::factory()->create(['name' => 'Somebody Elses Ward']);

        $this->actingAs($admin)
            ->get(route('admin.facility'))
            ->assertSeeTextInOrder(['Departments', 'Old Ward', '0', 'staff', 'Inactive', 'Reception', '2', 'staff'])
            ->assertDontSeeText('Somebody Elses Ward');
    }

    public function test_says_so_and_offers_to_add_one_when_there_are_no_departments(): void
    {
        $this->actingAs(User::factory()->for(Facility::factory()->create())->create())
            ->get(route('admin.facility'))
            ->assertSeeText('No departments yet.')
            ->assertSee(route('departments.create'), false);
    }

    public function test_shows_the_status_as_a_pill_in_the_tone_of_that_status(): void
    {
        $active = User::factory()->for(Facility::factory()->create())->create();
        $this->actingAs($active)
            ->get(route('admin.facility'))
            ->assertSee('cf-status-pill cf-status-pill--ok', false);

        $suspended = User::factory()->for(Facility::factory()->suspended()->create())->create();
        $this->actingAs($suspended)
            ->get(route('admin.facility'))
            ->assertRedirect();
    }
}
