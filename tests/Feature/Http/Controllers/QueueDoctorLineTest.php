<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DepartmentType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueueDoctorLineTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $consultation;

    private Department $laboratory;

    private User $wanjiku;

    private User $kamau;

    private User $nurse;

    private User $admin;

    private User $labDoctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->consultation = Department::factory()->for($this->facility)->assigningDoctors()->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
        $this->wanjiku = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Wanjiku Mwangi', 'department_id' => $this->consultation->id]);
        $this->kamau = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Kamau Njoroge', 'department_id' => $this->consultation->id]);
        $this->nurse = User::factory()->for($this->facility)->nurse()->create(['department_id' => $this->consultation->id]);
        $this->admin = User::factory()->for($this->facility)->create();
        $this->labDoctor = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->laboratory->id]);
    }

    private function patient(string $name): Patient
    {
        return Patient::factory()->for($this->facility)->create(['name' => $name]);
    }

    private function inLine(User $doctor, string $name, int $number, VisitStatus $status = VisitStatus::Waiting): Visit
    {
        return Visit::factory()->inLineOf($doctor, $number)->create(['patient_id' => $this->patient($name)->id, 'queue_number' => 40 + $number, 'status' => $status]);
    }

    private function unassigned(string $name): Visit
    {
        return Visit::factory()->for($this->facility)->create([
            'patient_id' => $this->patient($name)->id,
            'department_id' => $this->consultation->id,
            'queue_number' => 90,
        ]);
    }

    public function test_a_doctors_board_is_only_their_own_patients_in_their_own_order(): void
    {
        $this->inLine($this->wanjiku, 'Second Sam', 2);
        $this->inLine($this->wanjiku, 'First Fiona', 1);
        $this->inLine($this->kamau, 'Theirs Tom', 1);
        $this->unassigned('Legacy Lucy');

        $this->actingAs($this->wanjiku)
            ->get(route('queue.index'))
            ->assertOk()
            ->assertSeeInOrder(['First Fiona', 'Second Sam'])
            ->assertDontSeeText('Theirs Tom')
            ->assertDontSeeText('Legacy Lucy')
            ->assertSeeText('Your patients only');
    }

    public function test_a_doctors_stat_cards_count_only_their_own_patients(): void
    {
        $this->inLine($this->wanjiku, 'Mine One', 1);
        $this->inLine($this->kamau, 'Theirs One', 1);
        $this->inLine($this->kamau, 'Theirs Two', 2);

        $this->actingAs($this->wanjiku)
            ->get(route('queue.index'))
            ->assertViewHas('counts', ['waiting' => 1, 'awaiting' => 0, 'called' => 0, 'in_service' => 0]);
    }

    public function test_nurses_and_admins_still_see_every_doctors_patients_with_the_doctors_name(): void
    {
        $this->inLine($this->wanjiku, 'Mine One', 1);
        $this->inLine($this->kamau, 'Theirs One', 1);
        $this->unassigned('Legacy Lucy');

        foreach ([$this->nurse, $this->admin] as $staff) {
            $this->actingAs($staff)
                ->get(route('queue.index', ['department' => $this->consultation->id]))
                ->assertSeeText('Mine One')
                ->assertSeeText('Theirs One')
                ->assertSeeText('Legacy Lucy')
                ->assertSeeText('Dr. Wanjiku Mwangi')
                ->assertSeeText('Dr. Kamau Njoroge')
                ->assertSeeText('No doctor yet');
        }
    }

    public function test_a_department_without_doctor_lines_keeps_the_shared_queue_for_everyone(): void
    {
        $other = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->laboratory->id]);
        Visit::factory()->for($this->facility)->create(['patient_id' => $this->patient('Lab One')->id, 'department_id' => $this->laboratory->id]);
        Visit::factory()->for($this->facility)->create(['patient_id' => $this->patient('Lab Two')->id, 'department_id' => $this->laboratory->id]);

        foreach ([$this->labDoctor, $other] as $doctor) {
            $this->actingAs($doctor)
                ->get(route('queue.index'))
                ->assertSeeText('Lab One')
                ->assertSeeText('Lab Two')
                ->assertDontSeeText('Your patients only')
                ->assertDontSeeText('No doctor yet')
                ->assertDontSeeText('Go on duty');
        }
    }

    public function test_a_doctor_can_work_their_own_patient_but_not_another_doctors(): void
    {
        $mine = $this->inLine($this->wanjiku, 'Mine One', 1);
        $theirs = $this->inLine($this->kamau, 'Theirs One', 1);

        $this->actingAs($this->wanjiku)->post(route('queue.call', $mine))->assertRedirect();
        $this->actingAs($this->wanjiku)->post(route('queue.call', $theirs))->assertForbidden();

        $this->assertSame(VisitStatus::Called, $mine->fresh()->status);
        $this->assertSame(VisitStatus::Waiting, $theirs->fresh()->status);
        $this->assertCount(0, $theirs->events);
    }

    public function test_a_nurse_in_the_department_can_still_work_any_doctors_patient(): void
    {
        $theirs = $this->inLine($this->kamau, 'Theirs One', 1);

        $this->actingAs($this->nurse)->post(route('queue.call', $theirs))->assertRedirect();

        $this->assertSame(VisitStatus::Called, $theirs->fresh()->status);
    }

    public function test_a_doctor_has_an_on_duty_switch_that_says_which_way_it_will_flip(): void
    {
        $this->actingAs($this->wanjiku)
            ->get(route('queue.index'))
            ->assertSeeText('You are on duty')
            ->assertSeeText('Go off duty')
            ->assertSee('name="on_duty" value="0"', false);

        $this->wanjiku->update(['is_on_duty' => false]);

        $this->actingAs($this->wanjiku)
            ->get(route('queue.index'))
            ->assertSeeText('You are off duty')
            ->assertSeeText('Go on duty')
            ->assertSee('name="on_duty" value="1"', false);
    }

    public function test_only_doctors_see_the_duty_switch(): void
    {
        $this->actingAs($this->nurse)->get(route('queue.index'))->assertOk()->assertDontSeeText('Go off duty');
        $this->actingAs($this->admin)->get(route('queue.index', ['department' => $this->consultation->id]))->assertOk()->assertDontSeeText('Go off duty');
    }

    public function test_handing_a_patient_over_is_offered_to_admins_and_the_patients_own_doctor_while_they_are_still_waiting(): void
    {
        $this->inLine($this->wanjiku, 'Mine One', 1);

        $this->actingAs($this->admin)->get(route('queue.index', ['department' => $this->consultation->id]))->assertSeeText('Change doctor');
        $this->actingAs($this->wanjiku)->get(route('queue.index'))->assertSeeText('Change doctor');
        $this->actingAs($this->nurse)->get(route('queue.index'))->assertDontSeeText('Change doctor');
    }

    public function test_handing_over_is_not_offered_once_the_consultation_has_started(): void
    {
        $this->inLine($this->wanjiku, 'Mine One', 1, VisitStatus::InService);

        $this->actingAs($this->wanjiku)->get(route('queue.index'))->assertOk()->assertDontSeeText('Change doctor');
        $this->actingAs($this->admin)->get(route('queue.index', ['department' => $this->consultation->id]))->assertDontSeeText('Change doctor');
    }

    public function test_a_patient_with_no_doctor_can_be_given_one_by_an_admin(): void
    {
        $this->unassigned('Legacy Lucy');

        $this->actingAs($this->admin)
            ->get(route('queue.index', ['department' => $this->consultation->id]))
            ->assertSeeText('Assign doctor');
    }

    public function test_sending_a_patient_to_a_doctor_department_offers_the_doctors_on_duty_with_the_shortest_line_marked(): void
    {
        $this->inLine($this->kamau, 'Kamau One', 1);
        $this->inLine($this->kamau, 'Kamau Two', 2);
        Visit::factory()->for($this->facility)->inService()->create(['patient_id' => $this->patient('Seen Sarah')->id, 'department_id' => $this->laboratory->id]);

        $this->actingAs($this->labDoctor)
            ->get(route('queue.index'))
            ->assertSeeText('Dr. Wanjiku Mwangi')
            ->assertSeeText('shortest line')
            ->assertSeeText('Dr. Kamau Njoroge')
            ->assertSeeText('2 waiting');
    }
}
