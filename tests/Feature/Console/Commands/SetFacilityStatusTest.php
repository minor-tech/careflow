<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\FacilityStatus;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetFacilityStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_approves_a_pending_facility_by_id_and_lets_its_staff_in(): void
    {
        $facility = Facility::factory()->pendingReview()->create(['name' => 'Upendo Clinic']);
        $admin = User::factory()->for($facility)->create();

        $this->artisan('facility:set-status', ['facility' => $facility->id, 'status' => 'active'])
            ->expectsOutputToContain('Upendo Clinic is now Active.')
            ->assertSuccessful();

        $this->assertSame(FacilityStatus::Active, $facility->fresh()->status);
        $this->actingAs($admin)->get(route('admin.facility'))->assertOk();
    }

    public function test_finds_a_facility_by_slug_and_can_suspend_it(): void
    {
        $facility = Facility::factory()->create(['name' => 'Upendo Clinic']);

        $this->artisan('facility:set-status', ['facility' => 'upendo-clinic', 'status' => 'suspended'])
            ->assertSuccessful();

        $this->assertSame(FacilityStatus::Suspended, $facility->fresh()->status);
    }

    public function test_rejects_an_unknown_status_and_changes_nothing(): void
    {
        $facility = Facility::factory()->pendingReview()->create();

        $this->artisan('facility:set-status', ['facility' => $facility->id, 'status' => 'live'])
            ->expectsOutputToContain('Status must be one of')
            ->assertFailed();

        $this->assertSame(FacilityStatus::PendingReview, $facility->fresh()->status);
    }

    public function test_reports_a_facility_that_does_not_exist(): void
    {
        $this->artisan('facility:set-status', ['facility' => '999', 'status' => 'active'])
            ->expectsOutputToContain('No facility found')
            ->assertFailed();
    }
}
