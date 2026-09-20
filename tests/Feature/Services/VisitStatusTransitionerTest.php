<?php

namespace Tests\Feature\Services;

use App\Enums\VisitEventType;
use App\Enums\VisitStatus;
use App\Exceptions\InvalidVisitTransition;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Models\Visit;
use App\Services\VisitStatusTransitioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisitStatusTransitionerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every move that is allowed, with the event it logs.
     *
     * @return array<string, array{VisitStatus, VisitStatus, VisitEventType}>
     */
    public static function allowedMoves(): array
    {
        return [
            'call a waiting patient' => [VisitStatus::Waiting, VisitStatus::Called, VisitEventType::Called],
            'cancel a waiting patient' => [VisitStatus::Waiting, VisitStatus::Cancelled, VisitEventType::Cancelled],
            'start a called patient' => [VisitStatus::Called, VisitStatus::InService, VisitEventType::Started],
            'recall a patient who did not respond' => [VisitStatus::Called, VisitStatus::Waiting, VisitEventType::Recalled],
            'cancel a called patient who never came' => [VisitStatus::Called, VisitStatus::Cancelled, VisitEventType::Cancelled],
            'complete a patient being served' => [VisitStatus::InService, VisitStatus::Completed, VisitEventType::Completed],
        ];
    }

    /**
     * Every other pair of statuses, including "moving" to the status it is already in.
     *
     * @return array<string, array{VisitStatus, VisitStatus}>
     */
    public static function disallowedMoves(): array
    {
        $allowed = array_map(fn (array $move) => [$move[0], $move[1]], array_values(self::allowedMoves()));
        $moves = [];

        foreach (VisitStatus::cases() as $from) {
            foreach (VisitStatus::cases() as $to) {
                if (in_array([$from, $to], $allowed, true)) {
                    continue;
                }

                $moves["{$from->value} to {$to->value}"] = [$from, $to];
            }
        }

        return $moves;
    }

    private function visitAt(VisitStatus $status): Visit
    {
        $facility = Facility::factory()->create();
        $department = Department::factory()->for($facility)->create();

        return Visit::factory()->for($facility)->create(['department_id' => $department->id, 'status' => $status]);
    }

    private function staff(Visit $visit): User
    {
        return User::factory()->for($visit->facility)->doctor()->create();
    }

    #[DataProvider('allowedMoves')]
    public function test_makes_every_allowed_move_and_logs_who_did_it(VisitStatus $from, VisitStatus $to, VisitEventType $event): void
    {
        $visit = $this->visitAt($from);
        $actor = $this->staff($visit);

        $result = app(VisitStatusTransitioner::class)->transition($visit, $to, $actor);

        $this->assertSame($to, $result->status);
        $this->assertSame($to, $visit->fresh()->status);

        $log = $visit->events;
        $this->assertCount(1, $log);
        $this->assertSame($event, $log->first()->event);
        $this->assertSame($actor->id, $log->first()->user_id);
        $this->assertSame($visit->department_id, $log->first()->department_id);
        $this->assertNotNull($log->first()->created_at);
    }

    #[DataProvider('disallowedMoves')]
    public function test_refuses_every_other_move_and_changes_and_logs_nothing(VisitStatus $from, VisitStatus $to): void
    {
        $visit = $this->visitAt($from);

        try {
            app(VisitStatusTransitioner::class)->transition($visit, $to, $this->staff($visit));
            $this->fail("Moving from {$from->value} to {$to->value} should have been refused.");
        } catch (InvalidVisitTransition $exception) {
            $this->assertSame($from, $exception->from);
            $this->assertSame($to, $exception->to);
        }

        $this->assertSame($from, $visit->fresh()->status);
        $this->assertDatabaseCount('visit_events', 0);
    }

    public function test_the_refusal_is_a_message_staff_can_read(): void
    {
        $visit = $this->visitAt(VisitStatus::Called);
        $visit->update(['queue_number' => 19]);

        try {
            app(VisitStatusTransitioner::class)->transition($visit, VisitStatus::Called, $this->staff($visit));
            $this->fail('Expected a refusal.');
        } catch (InvalidVisitTransition $exception) {
            $this->assertSame("#19 is already Called, so that can't be done. The queue shows the latest status.", $exception->getMessage());
        }
    }

    public function test_only_completing_a_visit_records_a_completion_time(): void
    {
        $transitioner = app(VisitStatusTransitioner::class);
        $visit = $this->visitAt(VisitStatus::Waiting);
        $actor = $this->staff($visit);

        $transitioner->transition($visit, VisitStatus::Called, $actor);
        $transitioner->transition($visit, VisitStatus::InService, $actor);
        $this->assertNull($visit->fresh()->completed_at);

        $this->travelTo(now()->setDateTime(2026, 9, 18, 11, 30, 0));
        $completed = $transitioner->transition($visit, VisitStatus::Completed, $actor);

        $this->assertSame('2026-09-18 11:30:00', $completed->completed_at->toDateTimeString());
    }

    public function test_cancelling_does_not_count_as_completing(): void
    {
        $visit = $this->visitAt(VisitStatus::Waiting);

        $cancelled = app(VisitStatusTransitioner::class)->transition($visit, VisitStatus::Cancelled, $this->staff($visit));

        $this->assertNull($cancelled->completed_at);
    }

    public function test_a_stale_copy_of_a_visit_cannot_repeat_a_move_someone_else_already_made(): void
    {
        $transitioner = app(VisitStatusTransitioner::class);
        $visit = $this->visitAt(VisitStatus::Waiting);
        $stale = Visit::find($visit->id);

        $transitioner->transition($visit, VisitStatus::Called, $this->staff($visit));

        $this->expectException(InvalidVisitTransition::class);

        try {
            $transitioner->transition($stale, VisitStatus::Called, $this->staff($visit));
        } finally {
            $this->assertCount(1, $visit->events);
        }
    }

    public function test_the_full_journey_is_logged_in_order_with_each_persons_own_name(): void
    {
        $transitioner = app(VisitStatusTransitioner::class);
        $visit = $this->visitAt(VisitStatus::Waiting);
        $receptionist = User::factory()->for($visit->facility)->receptionist()->create();
        $doctor = $this->staff($visit);

        $transitioner->transition($visit, VisitStatus::Called, $receptionist);
        $transitioner->transition($visit, VisitStatus::InService, $doctor);
        $transitioner->transition($visit, VisitStatus::Completed, $doctor);

        $this->assertSame(
            [[VisitEventType::Called, $receptionist->id], [VisitEventType::Started, $doctor->id], [VisitEventType::Completed, $doctor->id]],
            $visit->events->map(fn ($event) => [$event->event, $event->user_id])->all(),
        );
    }

    public function test_a_failure_writing_the_log_undoes_the_status_change(): void
    {
        $visit = $this->visitAt(VisitStatus::Waiting);
        $ghost = User::factory()->make(['id' => 999999]);

        try {
            app(VisitStatusTransitioner::class)->transition($visit, VisitStatus::Called, $ghost);
            $this->fail('The log entry should not have been written for a user that does not exist.');
        } catch (QueryException) {
        }

        $this->assertSame(VisitStatus::Waiting, $visit->fresh()->status);
        $this->assertDatabaseCount('visit_events', 0);
    }

    public function test_reports_which_moves_are_possible(): void
    {
        $transitioner = app(VisitStatusTransitioner::class);

        $this->assertTrue($transitioner->canTransition(VisitStatus::Waiting, VisitStatus::Called));
        $this->assertFalse($transitioner->canTransition(VisitStatus::Waiting, VisitStatus::Completed));
        $this->assertFalse($transitioner->canTransition(VisitStatus::Completed, VisitStatus::Waiting));
    }
}
