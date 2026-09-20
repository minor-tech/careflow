<?php

namespace Tests\Feature\Models;

use App\Enums\DepartmentType;
use App\Enums\FacilityStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_facility_owns_its_departments_and_users(): void
    {
        $facility = Facility::factory()->create();
        $department = Department::factory()->for($facility)->create();
        $user = User::factory()->for($facility)->create(['department_id' => $department->id]);

        $this->assertTrue($facility->departments->contains($department));
        $this->assertTrue($facility->users->contains($user));
        $this->assertTrue($department->facility->is($facility));
        $this->assertTrue($department->users->contains($user));
        $this->assertTrue($user->department->is($department));
    }

    public function test_deleting_a_facility_removes_its_departments_and_users(): void
    {
        $facility = Facility::factory()->create();
        Department::factory()->for($facility)->create();
        User::factory()->for($facility)->create();

        $facility->delete();

        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_status_defaults_to_pending_review_when_not_given(): void
    {
        $attributes = Facility::factory()->raw();
        unset($attributes['status']);

        $facility = Facility::create($attributes)->fresh();

        $this->assertSame(FacilityStatus::PendingReview, $facility->status);
        $this->assertFalse($facility->isActive());
    }

    public function test_only_active_status_counts_as_active(): void
    {
        $this->assertTrue(Facility::factory()->make()->isActive());
        $this->assertFalse(Facility::factory()->pendingReview()->make()->isActive());
        $this->assertFalse(Facility::factory()->suspended()->make()->isActive());
    }

    public function test_slug_is_generated_from_the_name_and_kept_unique(): void
    {
        $first = Facility::factory()->create(['name' => 'Upendo Health Centre']);
        $second = Facility::factory()->create(['name' => 'Upendo Health Centre']);

        $this->assertSame('upendo-health-centre', $first->slug);
        $this->assertSame('upendo-health-centre-2', $second->slug);
    }

    public function test_json_columns_and_department_type_round_trip_as_typed_values(): void
    {
        $facility = Facility::factory()->create(['operating_days' => ['mon', 'sat']]);
        $department = Department::factory()->for($facility)->create(['type' => DepartmentType::Laboratory]);

        $this->assertSame(['mon', 'sat'], $facility->fresh()->operating_days);
        $this->assertSame(DepartmentType::Laboratory, $department->fresh()->type);
    }

    public function test_department_knows_which_facility_it_belongs_to(): void
    {
        $facility = Facility::factory()->create();
        $department = Department::factory()->for($facility)->create();

        $this->assertTrue($department->belongsToFacility($facility->id));
        $this->assertFalse($department->belongsToFacility($facility->id + 1));
    }

    public function test_its_tracking_entry_address_is_built_on_the_app_url_and_its_own_slug(): void
    {
        config(['app.url' => 'https://careflow.example/']);
        $facility = Facility::factory()->create(['slug' => 'upendo']);

        $this->assertSame('https://careflow.example/upendo/track', $facility->trackingEntryUrl());
    }
}
