<?php

namespace Tests\Feature\Models;

use App\Enums\DepartmentType;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\QueueCounter;
use App\Models\User;
use App\Models\Visit;
use App\Support\TrackingToken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visit_knows_its_facility_patient_department_and_registering_staff_member(): void
    {
        $facility = Facility::factory()->create();
        $department = Department::factory()->for($facility)->create();
        $staff = User::factory()->for($facility)->receptionist()->create();

        $visit = Visit::factory()->for($facility)->create(['department_id' => $department->id, 'created_by' => $staff->id]);

        $this->assertTrue($visit->facility->is($facility));
        $this->assertTrue($visit->department->is($department));
        $this->assertTrue($visit->creator->is($staff));
        $this->assertSame($facility->id, $visit->patient->facility_id);
        $this->assertTrue($department->visits->contains($visit));
    }

    public function test_a_new_visit_is_waiting_unless_told_otherwise(): void
    {
        $attributes = Visit::factory()->raw();
        unset($attributes['status']);

        $visit = Visit::create($attributes)->fresh();

        $this->assertSame(VisitStatus::Waiting, $visit->status);
        $this->assertNull($visit->completed_at);
    }

    public function test_department_and_creator_are_optional_and_survive_their_deletion(): void
    {
        $facility = Facility::factory()->create();
        $department = Department::factory()->for($facility)->create();
        $staff = User::factory()->for($facility)->receptionist()->create();
        $visit = Visit::factory()->for($facility)->create(['department_id' => $department->id, 'created_by' => $staff->id]);

        $department->delete();
        $staff->delete();

        $visit->refresh();
        $this->assertNull($visit->department_id);
        $this->assertNull($visit->created_by);
    }

    public function test_before_any_transfer_a_visit_goes_by_its_registration_number(): void
    {
        $visit = Visit::factory()->create(['queue_number' => 27, 'department_queue_number' => null]);

        $this->assertSame('#27', $visit->queueLabel());
    }

    public function test_after_a_transfer_a_visit_goes_by_the_departments_letter_and_its_own_number(): void
    {
        $facility = Facility::factory()->create();
        $laboratory = Department::factory()->for($facility)->create(['type' => DepartmentType::Laboratory]);
        $visit = Visit::factory()->for($facility)->create([
            'department_id' => $laboratory->id,
            'queue_number' => 27,
            'department_queue_number' => 8,
        ]);

        $this->assertSame('L-8', $visit->queueLabel());
        $this->assertSame(27, $visit->queue_number);
    }

    public function test_the_joined_queue_time_is_arrival_at_the_department_or_else_registration(): void
    {
        $arrived = Visit::factory()->create(['created_at' => '2026-09-18 08:00:00', 'department_entered_at' => '2026-09-18 09:30:00']);
        $firstLeg = Visit::factory()->create(['created_at' => '2026-09-18 08:00:00', 'department_entered_at' => null]);

        $this->assertSame('2026-09-18 09:30:00', $arrived->joinedQueueAt()->toDateTimeString());
        $this->assertSame('2026-09-18 08:00:00', $firstLeg->joinedQueueAt()->toDateTimeString());
    }

    public function test_belongs_to_facility_compares_the_facility_id(): void
    {
        $visit = Visit::factory()->create();

        $this->assertTrue($visit->belongsToFacility($visit->facility_id));
        $this->assertFalse($visit->belongsToFacility($visit->facility_id + 1));
    }

    public function test_every_new_visit_gets_its_own_tracking_token(): void
    {
        $tokens = Visit::factory()->count(5)->create()->pluck('tracking_token');

        $this->assertCount(5, $tokens->unique());
        $tokens->each(fn ($token) => $this->assertMatchesRegularExpression('/^'.TrackingToken::PATTERN.'$/', $token));
    }

    public function test_a_token_given_on_creation_is_kept(): void
    {
        $visit = Visit::factory()->create(['tracking_token' => 'abcdefghjkmnpq']);

        $this->assertSame('abcdefghjkmnpq', $visit->fresh()->tracking_token);
    }

    public function test_the_token_never_changes_as_the_visit_moves_on(): void
    {
        $visit = Visit::factory()->create();
        $token = $visit->tracking_token;

        $visit->update(['status' => VisitStatus::Completed]);

        $this->assertSame($token, $visit->fresh()->tracking_token);
    }

    public function test_two_visits_cannot_share_a_token(): void
    {
        Visit::factory()->create(['tracking_token' => 'abcdefghjkmnpq']);

        $this->expectException(UniqueConstraintViolationException::class);
        Visit::factory()->create(['tracking_token' => 'abcdefghjkmnpq']);
    }

    public function test_the_tracking_url_points_at_the_public_page_for_that_token(): void
    {
        $visit = Visit::factory()->create(['tracking_token' => 'abcdefghjkmnpq']);

        $this->assertSame(url('/t/abcdefghjkmnpq'), $visit->trackingUrl());
        $this->assertNull(Visit::factory()->make(['tracking_token' => null])->trackingUrl());
    }

    public function test_the_tracking_url_is_built_on_the_configured_app_url_not_the_address_staff_used(): void
    {
        config(['app.url' => 'https://careflow.example/']);
        $visit = Visit::factory()->create(['tracking_token' => 'abcdefghjkmnpq']);

        $this->get('http://localhost:8000/login');

        $this->assertSame('https://careflow.example/t/abcdefghjkmnpq', $visit->trackingUrl());
    }

    public function test_a_facility_has_at_most_one_queue_counter_per_day(): void
    {
        $facility = Facility::factory()->create();
        QueueCounter::factory()->for($facility)->create(['date' => '2026-09-18']);

        QueueCounter::factory()->for($facility)->create(['date' => '2026-09-19']);
        $this->assertDatabaseCount('queue_counters', 2);

        $this->expectException(UniqueConstraintViolationException::class);
        QueueCounter::factory()->for($facility)->create(['date' => '2026-09-18']);
    }

    public function test_the_queue_code_is_v_and_the_padded_registration_number(): void
    {
        $this->assertSame('V027', Visit::factory()->make(['queue_number' => 27])->queueCode());
    }

    public function test_the_pin_hash_never_leaves_the_model_when_it_is_serialised(): void
    {
        $visit = Visit::factory()->withAccessPin('7394')->create();

        $this->assertArrayNotHasKey('access_pin_hash', $visit->toArray());
        $this->assertStringNotContainsString($visit->access_pin_hash, $visit->toJson());
    }

    public function test_unfinished_leaves_out_completed_and_cancelled_visits_only(): void
    {
        $facility = Facility::factory()->create();
        $waiting = Visit::factory()->for($facility)->create(['status' => VisitStatus::Waiting]);
        $called = Visit::factory()->for($facility)->create(['status' => VisitStatus::Called]);
        $serving = Visit::factory()->for($facility)->create(['status' => VisitStatus::InService]);
        Visit::factory()->for($facility)->create(['status' => VisitStatus::Completed]);
        Visit::factory()->for($facility)->create(['status' => VisitStatus::Cancelled]);

        $this->assertEqualsCanonicalizing(
            [$waiting->id, $called->id, $serving->id],
            Visit::unfinished()->pluck('id')->all(),
        );
    }
}
