<?php

namespace Tests\Feature\Services;

use App\Enums\DepartmentType;
use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Exceptions\InvalidDepartmentTransfer;
use App\Exceptions\InvalidVisitTransition;
use App\Models\Department;
use App\Models\Facility;
use App\Models\QueueCounter;
use App\Models\User;
use App\Models\Visit;
use App\Services\QueueNumberGenerator;
use App\Services\VisitStatusTransitioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class VisitStatusTransitionerTransferTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Department $consultation;

    private Department $laboratory;

    private User $doctor;

    private VisitStatusTransitioner $transitioner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->consultation = Department::factory()->for($this->facility)->create(['name' => 'Consultation', 'type' => DepartmentType::Consultation]);
        $this->laboratory = Department::factory()->for($this->facility)->create(['name' => 'Laboratory', 'type' => DepartmentType::Laboratory]);
        $this->doctor = User::factory()->for($this->facility)->doctor()->create(['department_id' => $this->consultation->id]);
        $this->transitioner = app(VisitStatusTransitioner::class);
    }

    private function visit(VisitStatus $status = VisitStatus::InService, int $queueNumber = 27): Visit
    {
        return Visit::factory()->for($this->facility)->create([
            'department_id' => $this->consultation->id,
            'queue_number' => $queueNumber,
            'status' => $status,
        ]);
    }

    public function test_a_patient_being_served_joins_the_new_departments_queue_waiting_with_its_own_number(): void
    {
        $this->travelTo(now()->setDateTime(2026, 9, 18, 10, 30, 0));
        $visit = $this->visit();

        $result = $this->transitioner->transferToDepartment($visit, $this->laboratory, $this->doctor);

        $this->assertSame($this->laboratory->id, $result->department_id);
        $this->assertSame(VisitStatus::Waiting, $result->status);
        $this->assertSame(1, $result->department_queue_number);
        $this->assertSame('2026-09-18 10:30:00', $result->department_entered_at->toDateTimeString());
        $this->assertEquals($result->getAttributes(), $visit->fresh()->getAttributes());
    }

    public function test_the_patients_registration_number_never_changes(): void
    {
        $visit = $this->visit(queueNumber: 27);

        $this->transitioner->transferToDepartment($visit, $this->laboratory, $this->doctor);

        $this->assertSame(27, $visit->fresh()->queue_number);
    }

    public function test_the_transfer_is_logged_against_the_new_department_and_the_staff_member_who_did_it(): void
    {
        $visit = $this->visit();

        $this->transitioner->transferToDepartment($visit, $this->laboratory, $this->doctor);

        $event = $visit->events->sole();
        $this->assertSame(VisitEventType::Transferred, $event->event);
        $this->assertSame($this->laboratory->id, $event->department_id);
        $this->assertSame($this->doctor->id, $event->user_id);
    }

    public function test_the_next_patient_into_the_same_department_gets_the_next_local_number(): void
    {
        $numbers = [];

        foreach ([1, 2, 3] as $n) {
            $numbers[] = $this->transitioner->transferToDepartment($this->visit(queueNumber: 20 + $n), $this->laboratory, $this->doctor)->department_queue_number;
        }

        $this->assertSame([1, 2, 3], $numbers);
    }

    public function test_the_local_number_comes_from_the_destination_departments_own_sequence(): void
    {
        $generator = app(QueueNumberGenerator::class);
        foreach (range(1, 7) as $ignored) {
            $generator->next($this->facility->id, $this->laboratory->id);
        }
        foreach (range(1, 40) as $ignored) {
            $generator->next($this->facility->id);
        }

        $result = $this->transitioner->transferToDepartment($this->visit(), $this->laboratory, $this->doctor);

        $this->assertSame(8, $result->department_queue_number);
        $this->assertSame(40, QueueCounter::whereNull('department_id')->sole()->last_number);
    }

    /**
     * @return array<string, array{VisitStatus}>
     */
    public static function statusesThatCannotBeTransferred(): array
    {
        return [
            'waiting' => [VisitStatus::Waiting],
            'called' => [VisitStatus::Called],
            'completed' => [VisitStatus::Completed],
            'cancelled' => [VisitStatus::Cancelled],
            'waiting for department' => [VisitStatus::WaitingDepartment],
        ];
    }

    #[DataProvider('statusesThatCannotBeTransferred')]
    public function test_only_a_patient_who_has_been_seen_can_be_sent_onward(VisitStatus $status): void
    {
        $visit = $this->visit($status);

        try {
            $this->transitioner->transferToDepartment($visit, $this->laboratory, $this->doctor);
            $this->fail('A visit that is not in service must not be transferable.');
        } catch (InvalidVisitTransition $exception) {
            $this->assertSame($status, $exception->from);
        }

        $fresh = $visit->fresh();
        $this->assertSame($this->consultation->id, $fresh->department_id);
        $this->assertSame($status, $fresh->status);
        $this->assertNull($fresh->department_queue_number);
        $this->assertDatabaseCount('visit_events', 0);
        $this->assertDatabaseCount('queue_counters', 0);
    }

    public function test_a_stale_copy_cannot_transfer_a_patient_who_has_already_been_moved(): void
    {
        $visit = $this->visit();
        $stale = Visit::find($visit->id);

        $this->transitioner->transferToDepartment($visit, $this->laboratory, $this->doctor);

        $this->expectException(InvalidVisitTransition::class);

        try {
            $this->transitioner->transferToDepartment($stale, $this->laboratory, $this->doctor);
        } finally {
            $this->assertCount(1, $visit->events);
            $this->assertSame(1, QueueCounter::where('department_id', $this->laboratory->id)->sole()->last_number);
        }
    }

    public function test_a_patient_cannot_be_sent_to_the_department_they_are_already_in(): void
    {
        $visit = $this->visit();

        $this->expectException(InvalidDepartmentTransfer::class);
        $this->expectExceptionMessage('#27 is already in Consultation.');

        $this->transitioner->transferToDepartment($visit, $this->consultation, $this->doctor);
    }

    public function test_a_patient_cannot_be_sent_to_a_department_that_is_not_active(): void
    {
        $closed = Department::factory()->for($this->facility)->inactive()->create(['name' => 'Closed Ward']);
        $visit = $this->visit();

        try {
            $this->transitioner->transferToDepartment($visit, $closed, $this->doctor);
            $this->fail('An inactive department must be refused.');
        } catch (InvalidDepartmentTransfer $exception) {
            $this->assertSame("Closed Ward isn't taking patients right now, so nobody can be sent there.", $exception->getMessage());
        }

        $this->assertSame($this->consultation->id, $visit->fresh()->department_id);
    }

    public function test_a_patient_cannot_be_sent_to_another_facilitys_department(): void
    {
        $foreign = Department::factory()->for(Facility::factory())->create();
        $visit = $this->visit();

        $this->expectException(InvalidDepartmentTransfer::class);

        $this->transitioner->transferToDepartment($visit, $foreign, $this->doctor);
    }

    public function test_a_refused_transfer_changes_logs_and_numbers_nothing(): void
    {
        $visit = $this->visit();

        try {
            $this->transitioner->transferToDepartment($visit, $this->consultation, $this->doctor);
        } catch (InvalidDepartmentTransfer) {
        }

        $this->assertSame(VisitStatus::InService, $visit->fresh()->status);
        $this->assertDatabaseCount('visit_events', 0);
        $this->assertDatabaseCount('queue_counters', 0);
    }

    public function test_a_failure_part_way_undoes_the_move_the_number_and_the_log(): void
    {
        $visit = $this->visit();
        $ghost = User::factory()->make(['id' => 999999]);

        try {
            $this->transitioner->transferToDepartment($visit, $this->laboratory, $ghost);
            $this->fail('The log entry should not have been written for a user that does not exist.');
        } catch (QueryException) {
        }

        $fresh = $visit->fresh();
        $this->assertSame($this->consultation->id, $fresh->department_id);
        $this->assertSame(VisitStatus::InService, $fresh->status);
        $this->assertNull($fresh->department_queue_number);
        $this->assertNull(QueueCounter::where('department_id', $this->laboratory->id)->first());
        $this->assertDatabaseCount('visit_events', 0);
    }

    public function test_a_failure_taking_the_number_leaves_the_patient_where_they_were(): void
    {
        $this->mock(QueueNumberGenerator::class)
            ->shouldReceive('next')->once()->andThrow(new RuntimeException('counter unavailable'));

        $visit = $this->visit();

        try {
            app(VisitStatusTransitioner::class)->transferToDepartment($visit, $this->laboratory, $this->doctor);
            $this->fail('The failure should have propagated.');
        } catch (RuntimeException) {
        }

        $this->assertSame($this->consultation->id, $visit->fresh()->department_id);
        $this->assertSame(VisitStatus::InService, $visit->fresh()->status);
    }

    public function test_a_visit_can_be_sent_on_through_several_departments_and_can_come_back(): void
    {
        $pharmacy = Department::factory()->for($this->facility)->create(['type' => DepartmentType::Pharmacy]);
        $visit = $this->visit();

        foreach ([$this->laboratory, $pharmacy, $this->consultation, $this->laboratory] as $department) {
            $visit = $this->transitioner->transferToDepartment($visit, $department, $this->doctor);
            $this->assertSame($department->id, $visit->department_id);
            $this->assertSame(VisitStatus::Waiting, $visit->status);

            $visit = $this->transitioner->transition($visit, VisitStatus::Called, $this->doctor);
            $visit = $this->transitioner->transition($visit, VisitStatus::InService, $this->doctor);
        }

        $this->assertSame(27, $visit->queue_number);
        $this->assertSame(
            [$this->laboratory->id, $pharmacy->id, $this->consultation->id, $this->laboratory->id],
            $visit->events->where('event', VisitEventType::Transferred)->pluck('department_id')->values()->all(),
        );
        // The second time round, Laboratory gave the patient a fresh number.
        $this->assertSame(2, $visit->department_queue_number);
    }

    public function test_the_last_department_can_still_complete_the_visit(): void
    {
        $visit = $this->transitioner->transferToDepartment($this->visit(), $this->laboratory, $this->doctor);
        $visit = $this->transitioner->transition($visit, VisitStatus::Called, $this->doctor);
        $visit = $this->transitioner->transition($visit, VisitStatus::InService, $this->doctor);

        $done = $this->transitioner->transition($visit, VisitStatus::Completed, $this->doctor);

        $this->assertSame(VisitStatus::Completed, $done->status);
        $this->assertNotNull($done->completed_at);
    }
}
