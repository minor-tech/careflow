<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DepartmentType;
use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A patient seen elsewhere (say the laboratory) going back to consultation
 * needs a doctor to go to, because consultation gives each patient their own.
 */
class QueueTransferToDoctorTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $consultation;

    private Department $laboratory;

    private Department $pharmacy;

    private User $wanjiku;

    private User $labTech;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->consultation = Department::factory()->for($this->facility)->assigningDoctors()->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
        $this->pharmacy = Department::factory()->for($this->facility)->create(['name' => 'Pharmacy', 'type' => DepartmentType::Pharmacy]);
        $this->wanjiku = User::factory()->for($this->facility)->doctor()->onDuty()->create(['name' => 'Wanjiku Mwangi', 'department_id' => $this->consultation->id]);
        $this->labTech = User::factory()->for($this->facility)->nurse()->create(['department_id' => $this->laboratory->id]);
    }

    private function inLab(): Visit
    {
        return Visit::factory()->for($this->facility)->inService()->create([
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => 'Julius Maina'])->id,
            'department_id' => $this->laboratory->id,
            'queue_number' => 27,
        ]);
    }

    private function send(Visit $visit, Department $to, ?User $doctor = null, ?User $by = null)
    {
        return $this->actingAs($by ?? $this->labTech)->post(
            route('queue.transfer', [$visit, $to]),
            $doctor === null ? [] : ['doctor_id' => $doctor->id],
        );
    }

    public function test_the_patient_joins_the_chosen_doctors_own_line_with_a_personal_number(): void
    {
        $visit = $this->inLab();

        $this->send($visit, $this->consultation, $this->wanjiku)
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Julius Maina is now waiting with Dr. Wanjiku Mwangi as C-1.');

        $visit->refresh();
        $this->assertSame($this->consultation->id, $visit->department_id);
        $this->assertSame(VisitStatus::Waiting, $visit->status);
        $this->assertSame($this->wanjiku->id, $visit->assigned_doctor_id);
        $this->assertSame(1, $visit->doctor_queue_number);
        $this->assertNull($visit->department_queue_number, 'The doctor\'s number is the one they go by.');
        $this->assertSame(27, $visit->queue_number, 'The registration number is never touched.');
    }

    public function test_it_is_logged_as_a_transfer_and_an_assignment_by_the_person_who_sent_them(): void
    {
        $visit = $this->inLab();

        $this->send($visit, $this->consultation, $this->wanjiku);

        $log = $visit->events;
        $this->assertSame([VisitEventType::Transferred, VisitEventType::DoctorAssigned], $log->pluck('event')->all());
        $this->assertSame(['doctor_id' => $this->wanjiku->id], $log->last()->meta);
        $this->assertSame([$this->labTech->id, $this->labTech->id], $log->pluck('user_id')->all());
        $this->assertSame([$this->consultation->id, $this->consultation->id], $log->pluck('department_id')->all());
    }

    public function test_a_department_that_gives_each_patient_a_doctor_will_not_take_a_patient_without_one(): void
    {
        $visit = $this->inLab();

        $this->send($visit, $this->consultation)
            ->assertSessionHasErrors(['queue' => 'Consultation gives each patient their own doctor: choose one to send them to.']);

        $this->assertSame($this->laboratory->id, $visit->fresh()->department_id);
        $this->assertSame(VisitStatus::InService, $visit->fresh()->status);
        $this->assertCount(0, $visit->events);
        $this->assertDatabaseCount('queue_counters', 0);
    }

    public function test_a_doctor_who_is_off_duty_or_from_another_department_is_refused(): void
    {
        $visit = $this->inLab();
        $away = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]);
        $labDoctor = User::factory()->for($this->facility)->doctor()->onDuty()->create(['department_id' => $this->laboratory->id]);

        foreach ([$away, $labDoctor] as $doctor) {
            $this->send($visit, $this->consultation, $doctor)
                ->assertSessionHasErrors(['queue' => 'That doctor isn\'t on duty in Consultation right now. Choose another.']);
        }

        $this->assertSame($this->laboratory->id, $visit->fresh()->department_id);
        $this->assertCount(0, $visit->events);
    }

    public function test_a_doctor_sent_to_a_department_without_doctor_lines_is_ignored(): void
    {
        $visit = $this->inLab();

        $this->send($visit, $this->pharmacy, $this->wanjiku)->assertSessionHasNoErrors();

        $visit->refresh();
        $this->assertSame($this->pharmacy->id, $visit->department_id);
        $this->assertNull($visit->assigned_doctor_id);
        $this->assertNull($visit->doctor_queue_number);
        $this->assertSame(1, $visit->department_queue_number);
        $this->assertSame([VisitEventType::Transferred], $visit->events->pluck('event')->all());
    }

    public function test_leaving_a_doctors_line_clears_the_place_in_it_but_keeps_the_doctor_as_history(): void
    {
        $visit = Visit::factory()->inLineOf($this->wanjiku, 3)->inService()->create([
            'patient_id' => Patient::factory()->for($this->facility)->create()->id,
            'queue_number' => 27,
        ]);

        $this->send($visit, $this->laboratory, by: $this->wanjiku)->assertSessionHasNoErrors();

        $visit->refresh()->load('department');
        $this->assertSame($this->wanjiku->id, $visit->assigned_doctor_id, 'The doctor stays on the record for their workload report.');
        $this->assertNull($visit->doctor_queue_number);
        $this->assertFalse($visit->isInDoctorQueue());
        $this->assertSame(1, $visit->department_queue_number);
        $this->assertSame('L-1', $visit->queueLabel());
    }

    public function test_doctors_cannot_send_another_doctors_patient_on(): void
    {
        $other = User::factory()->for($this->facility)->doctor()->onDuty()->create(['department_id' => $this->consultation->id]);
        $visit = Visit::factory()->inLineOf($this->wanjiku, 1)->inService()->create();

        $this->send($visit, $this->laboratory, by: $other)->assertForbidden();

        $this->assertSame($this->consultation->id, $visit->fresh()->department_id);
    }
}
