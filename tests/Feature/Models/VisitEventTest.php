<?php

namespace Tests\Feature\Models;

use App\Enums\VisitEventType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class VisitEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_event_knows_its_visit_department_and_staff_member(): void
    {
        $facility = Facility::factory()->create();
        $department = Department::factory()->for($facility)->create();
        $staff = User::factory()->for($facility)->doctor()->create();
        $visit = Visit::factory()->for($facility)->create(['department_id' => $department->id]);

        $event = VisitEvent::create([
            'visit_id' => $visit->id,
            'department_id' => $department->id,
            'event' => VisitEventType::Called,
            'user_id' => $staff->id,
        ]);

        $this->assertTrue($event->visit->is($visit));
        $this->assertTrue($event->department->is($department));
        $this->assertTrue($event->user->is($staff));
        $this->assertSame(VisitEventType::Called, $event->fresh()->event);
        $this->assertTrue($staff->visitEvents->contains($event));
        $this->assertTrue($department->visitEvents->contains($event));
    }

    public function test_only_a_created_at_is_recorded_and_it_is_set_automatically(): void
    {
        $this->travelTo(now()->setDateTime(2026, 9, 18, 10, 0, 0));

        $event = VisitEvent::factory()->create();

        $this->assertSame('2026-09-18 10:00:00', $event->fresh()->created_at->toDateTimeString());
        $this->assertArrayNotHasKey('updated_at', $event->fresh()->getAttributes());
    }

    public function test_department_and_staff_member_are_optional(): void
    {
        $event = VisitEvent::factory()->create(['department_id' => null, 'user_id' => null]);

        $this->assertNull($event->fresh()->department_id);
        $this->assertNull($event->fresh()->user_id);
    }

    public function test_an_event_cannot_be_changed_once_written(): void
    {
        $event = VisitEvent::factory()->create();

        $this->expectException(LogicException::class);

        $event->update(['event' => VisitEventType::Cancelled]);
    }

    public function test_an_event_cannot_be_deleted(): void
    {
        $event = VisitEvent::factory()->create();

        try {
            $event->delete();
            $this->fail('The event should not have been deleted.');
        } catch (LogicException) {
        }

        $this->assertModelExists($event);
    }

    public function test_a_visits_events_come_back_oldest_first(): void
    {
        $visit = Visit::factory()->create();

        foreach ([VisitEventType::Registered, VisitEventType::Called, VisitEventType::Started] as $type) {
            VisitEvent::factory()->for($visit)->create(['event' => $type]);
        }

        $this->assertSame(
            [VisitEventType::Registered, VisitEventType::Called, VisitEventType::Started],
            $visit->events->pluck('event')->all(),
        );
    }

    public function test_the_database_will_not_let_a_visit_with_a_history_be_deleted(): void
    {
        $event = VisitEvent::factory()->create();

        $this->expectException(QueryException::class);

        $event->visit->delete();
    }
}
