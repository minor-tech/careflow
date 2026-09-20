<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityStatusControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_admin_sees_the_registration_under_review_message(): void
    {
        $facility = Facility::factory()->pendingReview()->create(['name' => 'Upendo Clinic']);

        $this->actingAs(User::factory()->for($facility)->create())
            ->get(route('facility.status'))
            ->assertOk()
            ->assertSeeText('Upendo Clinic')
            ->assertSeeText("Your facility registration is under review. You'll receive a confirmation within 24 hours.");
    }

    public function test_suspended_admin_sees_that_the_facility_is_suspended(): void
    {
        $this->actingAs(User::factory()->for(Facility::factory()->suspended())->create())
            ->get(route('facility.status'))
            ->assertOk()
            ->assertSeeText('Facility suspended')
            ->assertDontSeeText('under review');
    }

    public function test_admin_of_an_active_facility_is_sent_on_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('facility.status'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_system_admin_has_no_facility_status_page(): void
    {
        $this->actingAs(User::factory()->systemAdmin()->create())
            ->get(route('facility.status'))
            ->assertForbidden();
    }

    public function test_rejected_admin_sees_that_the_registration_was_not_approved_and_why(): void
    {
        $facility = Facility::factory()->rejected('The KMPDC number does not match any register entry.')->create(['name' => 'Upendo Clinic']);

        $this->actingAs(User::factory()->for($facility)->create())
            ->get(route('facility.status'))
            ->assertOk()
            ->assertSeeText('Registration not approved')
            ->assertSeeText('The KMPDC number does not match any register entry.')
            ->assertDontSeeText('under review');
    }

    public function test_non_admin_staff_cannot_open_the_status_page(): void
    {
        $this->actingAs(User::factory()->for(Facility::factory()->pendingReview())->receptionist()->create())
            ->get(route('facility.status'))
            ->assertForbidden();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('facility.status'))->assertRedirect(route('login'));
    }
}
