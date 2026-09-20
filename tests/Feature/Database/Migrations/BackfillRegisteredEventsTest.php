<?php

namespace Tests\Feature\Database\Migrations;

use App\Enums\VisitEventType;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillRegisteredEventsTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        (require database_path('migrations/2026_09_18_165550_backfill_registered_events_for_existing_visits.php'))->up();
    }

    public function test_a_visit_from_before_the_audit_log_gets_a_registered_event_built_from_its_own_record(): void
    {
        $facility = Facility::factory()->create();
        $department = Department::factory()->for($facility)->create();
        $staff = User::factory()->for($facility)->receptionist()->create();
        $visit = Visit::factory()->for($facility)->create([
            'department_id' => $department->id,
            'created_by' => $staff->id,
            'created_at' => '2026-09-18 08:15:00',
        ]);

        $this->runMigration();

        $event = $visit->events->sole();
        $this->assertSame(VisitEventType::Registered, $event->event);
        $this->assertSame($staff->id, $event->user_id);
        $this->assertSame($department->id, $event->department_id);
        $this->assertSame('2026-09-18 08:15:00', $event->created_at->toDateTimeString());
    }

    public function test_it_can_run_again_without_adding_duplicates(): void
    {
        $visit = Visit::factory()->create();

        $this->runMigration();
        $this->runMigration();

        $this->assertCount(1, $visit->events);
    }

    public function test_visits_that_already_have_their_registered_event_are_left_alone(): void
    {
        $visit = Visit::factory()->create();
        VisitEvent::factory()->for($visit)->create(['event' => VisitEventType::Registered, 'user_id' => null]);

        $this->runMigration();

        $this->assertCount(1, $visit->events);
    }

    public function test_a_visit_without_a_department_or_creator_is_backfilled_too(): void
    {
        $visit = Visit::factory()->create(['department_id' => null, 'created_by' => null]);

        $this->runMigration();

        $event = $visit->events->sole();
        $this->assertNull($event->department_id);
        $this->assertNull($event->user_id);
    }
}
