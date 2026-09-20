<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DepartmentType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DutyControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $consultation;

    private User $wanjiku;

    private User $kamau;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->consultation = Department::factory()->for($this->facility)->assigningDoctors()->create(['type' => DepartmentType::Consultation]);
        $this->wanjiku = User::factory()->for($this->facility)->doctor()->create(['name' => 'Wanjiku Mwangi', 'department_id' => $this->consultation->id]);
        $this->kamau = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Kamau Njoroge', 'department_id' => $this->consultation->id]);
        $this->admin = User::factory()->for($this->facility)->create();
    }

    public function test_a_doctor_switches_themself_on_and_off_duty(): void
    {
        $this->actingAs($this->wanjiku)
            ->from(route('queue.index'))
            ->post(route('staff.duty', $this->wanjiku), ['on_duty' => 1])
            ->assertRedirect(route('queue.index'))
            ->assertSessionHas('success', 'You are now on duty.');

        $this->assertTrue($this->wanjiku->fresh()->isOnDuty());

        $this->actingAs($this->wanjiku)
            ->post(route('staff.duty', $this->wanjiku), ['on_duty' => 0])
            ->assertSessionHas('success', 'You are now off duty.');

        $this->assertFalse($this->wanjiku->fresh()->isOnDuty());
    }

    public function test_switching_the_way_it_already_is_changes_nothing(): void
    {
        $this->actingAs($this->kamau)->post(route('staff.duty', $this->kamau), ['on_duty' => 1])->assertSessionHasNoErrors();

        $this->assertTrue($this->kamau->fresh()->isOnDuty());
    }

    public function test_an_admin_can_switch_any_doctor_of_their_facility_and_it_names_the_doctor(): void
    {
        $this->actingAs($this->admin)
            ->post(route('staff.duty', $this->wanjiku), ['on_duty' => 1])
            ->assertSessionHas('success', 'Dr. Wanjiku Mwangi is now on duty.');

        $this->assertTrue($this->wanjiku->fresh()->isOnDuty());
    }

    public function test_a_doctor_cannot_switch_another_doctor(): void
    {
        $this->actingAs($this->wanjiku)->post(route('staff.duty', $this->kamau), ['on_duty' => 0])->assertForbidden();

        $this->assertTrue($this->kamau->fresh()->isOnDuty());
    }

    public function test_receptionists_and_nurses_cannot_switch_anyone(): void
    {
        foreach ([User::factory()->for($this->facility)->receptionist()->create(), User::factory()->for($this->facility)->nurse()->create()] as $staff) {
            $this->actingAs($staff)->post(route('staff.duty', $this->wanjiku), ['on_duty' => 1])->assertForbidden();
        }

        $this->assertFalse($this->wanjiku->fresh()->isOnDuty());
    }

    public function test_another_facilitys_admin_and_doctors_get_not_found(): void
    {
        $outsiderAdmin = User::factory()->for(Facility::factory()->create())->create();
        $outsiderDoctor = User::factory()->for(Facility::factory()->create())->doctor()->create();

        $this->actingAs($outsiderAdmin)->post(route('staff.duty', $this->wanjiku), ['on_duty' => 1])->assertNotFound();
        $this->actingAs($outsiderDoctor)->post(route('staff.duty', $this->wanjiku), ['on_duty' => 1])->assertNotFound();

        $this->assertFalse($this->wanjiku->fresh()->isOnDuty());
    }

    public function test_only_a_doctor_has_a_duty_status_to_switch(): void
    {
        $nurse = User::factory()->for($this->facility)->nurse()->create();

        $this->actingAs($this->admin)->post(route('staff.duty', $nurse), ['on_duty' => 1])->assertNotFound();

        $this->assertFalse($nurse->fresh()->isOnDuty());
    }

    public function test_the_switch_must_say_which_way(): void
    {
        $this->actingAs($this->wanjiku)->post(route('staff.duty', $this->wanjiku), [])->assertSessionHasErrors('on_duty');
        $this->actingAs($this->wanjiku)->post(route('staff.duty', $this->wanjiku), ['on_duty' => 'maybe'])->assertSessionHasErrors('on_duty');

        $this->assertFalse($this->wanjiku->fresh()->isOnDuty());
    }

    public function test_the_staff_list_shows_who_is_on_duty_and_lets_an_admin_flip_it(): void
    {
        $this->actingAs($this->admin)
            ->get(route('staff.index'))
            ->assertOk()
            ->assertSeeText('On duty')
            ->assertSeeText('Off duty')
            ->assertSeeText('Set off duty')
            ->assertSeeText('Set on duty');
    }
}
