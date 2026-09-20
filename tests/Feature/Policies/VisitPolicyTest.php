<?php

namespace Tests\Feature\Policies;

use App\Enums\DepartmentType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class VisitPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $consultation;

    private Department $laboratory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->consultation = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Laboratory]);
    }

    private function visitIn(?Department $department): Visit
    {
        return Visit::factory()->for($this->facility)->create(['department_id' => $department?->id]);
    }

    private function staff(string $role, ?Department $department): User
    {
        return User::factory()->for($this->facility)->{$role}()->create(['department_id' => $department?->id]);
    }

    public function test_an_admin_can_reset_the_pin_of_a_visit_in_any_department_or_none(): void
    {
        $admin = User::factory()->for($this->facility)->create();

        foreach ([$this->consultation, $this->laboratory, null] as $department) {
            $this->assertTrue(Gate::forUser($admin)->allows('resetPin', $this->visitIn($department)));
        }
    }

    public function test_reception_can_reset_the_pin_only_of_their_own_departments_visits(): void
    {
        $receptionist = $this->staff('receptionist', $this->consultation);

        $this->assertTrue(Gate::forUser($receptionist)->allows('resetPin', $this->visitIn($this->consultation)));
        $this->assertTrue(Gate::forUser($receptionist)->denies('resetPin', $this->visitIn($this->laboratory)));
        $this->assertTrue(Gate::forUser($receptionist)->denies('resetPin', $this->visitIn(null)));
    }

    public function test_doctors_and_nurses_cannot_reset_a_pin_even_in_their_own_department(): void
    {
        $visit = $this->visitIn($this->consultation);

        foreach (['doctor', 'nurse'] as $role) {
            $response = Gate::forUser($this->staff($role, $this->consultation))->inspect('resetPin', $visit);

            $this->assertTrue($response->denied(), "A {$role} must not reset a PIN.");
            $this->assertSame("Only reception or an admin can reset a patient's PIN.", $response->message());
        }
    }

    public function test_staff_of_another_facility_are_told_the_visit_does_not_exist(): void
    {
        $outsider = User::factory()->for(Facility::factory()->create())->create();

        $response = Gate::forUser($outsider)->inspect('resetPin', $this->visitIn($this->consultation));

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public function test_a_doctor_works_only_their_own_patients_where_a_department_gives_each_patient_a_doctor(): void
    {
        $this->consultation->update(['requires_doctor_assignment' => true]);
        $wanjiku = $this->staff('doctor', $this->consultation);
        $kamau = $this->staff('doctor', $this->consultation);
        $ownPatient = Visit::factory()->inLineOf($wanjiku)->create();
        $otherPatient = Visit::factory()->inLineOf($kamau)->create();
        $nobodys = $this->visitIn($this->consultation);

        $this->assertTrue(Gate::forUser($wanjiku)->allows('updateQueue', $ownPatient));
        $this->assertSame('This patient is assigned to another doctor.', Gate::forUser($wanjiku)->inspect('updateQueue', $otherPatient)->message());
        $this->assertTrue(Gate::forUser($wanjiku)->denies('updateQueue', $nobodys));

        // Everyone else in the department, and an admin, work any doctor's patients.
        foreach ([$this->staff('nurse', $this->consultation), $this->staff('receptionist', $this->consultation), User::factory()->for($this->facility)->create()] as $staff) {
            $this->assertTrue(Gate::forUser($staff)->allows('updateQueue', $otherPatient), 'A '.$staff->role->value.' can work any doctor\'s patient.');
        }
    }

    public function test_doctors_share_a_departments_queue_where_no_doctor_lines_exist(): void
    {
        $wanjiku = $this->staff('doctor', $this->laboratory);
        $kamau = $this->staff('doctor', $this->laboratory);

        $this->assertTrue(Gate::forUser($wanjiku)->allows('updateQueue', Visit::factory()->inLineOf($kamau)->create()));
        $this->assertTrue(Gate::forUser($wanjiku)->allows('updateQueue', $this->visitIn($this->laboratory)));
    }

    public function test_only_an_admin_or_the_patients_own_doctor_can_reassign(): void
    {
        $this->consultation->update(['requires_doctor_assignment' => true]);
        $wanjiku = $this->staff('doctor', $this->consultation);
        $kamau = $this->staff('doctor', $this->consultation);
        $visit = Visit::factory()->inLineOf($wanjiku)->create();
        $unassigned = $this->visitIn($this->consultation);

        $this->assertTrue(Gate::forUser($wanjiku)->allows('reassignDoctor', $visit));
        $this->assertTrue(Gate::forUser(User::factory()->for($this->facility)->create())->allows('reassignDoctor', $visit));
        $this->assertTrue(Gate::forUser(User::factory()->for($this->facility)->create())->allows('reassignDoctor', $unassigned));

        foreach ([$kamau, $this->staff('nurse', $this->consultation), $this->staff('receptionist', $this->consultation)] as $staff) {
            $this->assertSame('Only an admin or the patient\'s own doctor can change who they are assigned to.', Gate::forUser($staff)->inspect('reassignDoctor', $visit)->message(), $staff->role->value);
        }

        $this->assertTrue(Gate::forUser($kamau)->denies('reassignDoctor', $unassigned), 'A doctor cannot take an unassigned patient for themself.');
    }

    public function test_another_facilitys_staff_cannot_reassign_and_are_told_it_does_not_exist(): void
    {
        $visit = Visit::factory()->inLineOf($this->staff('doctor', $this->consultation))->create();
        $outsiderAdmin = User::factory()->for(Facility::factory()->create())->create();

        $response = Gate::forUser($outsiderAdmin)->inspect('reassignDoctor', $visit);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }
}
