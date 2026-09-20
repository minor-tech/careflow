<?php

namespace Tests\Feature;

use App\Actions\CreateStaffMember;
use App\Enums\DepartmentType;
use App\Enums\FacilityStatus;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\StaffAccountCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\RegistersFacilities;
use Tests\TestCase;

class FacilityRegistrationSubmissionTest extends TestCase
{
    use RefreshDatabase;
    use RegistersFacilities;

    private function submit(array $overrides = [])
    {
        return $this->post(route('facility.register.store'), $this->stepPayload(9, $overrides));
    }

    public function test_submitting_creates_a_facility_pending_review_and_shows_the_review_message(): void
    {
        $this->completeSteps(8);

        $this->submit()->assertRedirect(route('facility.register.submitted'));

        $facility = Facility::sole();
        $this->assertSame(FacilityStatus::PendingReview, $facility->status);
        $this->assertSame('Upendo Health Centre', $facility->name);
        $this->assertSame('upendo-health-centre', $facility->slug);
        $this->assertSame(['mon', 'tue', 'wed'], $facility->operating_days);
        $this->assertSame(['sms', 'email'], $facility->notification_channels);
        $this->assertSame('Wanjiru Kamau', $facility->signature_name);
        $this->assertNotNull($facility->terms_accepted_at);
        $this->assertNotNull($facility->dpa_accepted_at);

        $this->get(route('facility.register.submitted'))
            ->assertSeeText('Upendo Health Centre')
            ->assertSeeText("Your facility registration is under review. You'll receive a confirmation within 24 hours.");
    }

    public function test_confirmation_screen_never_implies_the_facility_is_active(): void
    {
        $this->completeSteps(8);

        $this->followRedirects($this->submit())
            ->assertSeeText('under review')
            ->assertDontSeeText('activated')
            ->assertDontSeeText('is now live');
    }

    public function test_submitting_creates_the_admin_user_who_can_log_in_with_the_chosen_password(): void
    {
        $this->completeSteps(8);
        $this->submit();

        $admin = User::where('email', 'wanjiru@upendo.test')->sole();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertSame(Facility::sole()->id, $admin->facility_id);
        $this->assertSame('Facility Manager', $admin->title);
        $this->assertTrue($admin->two_factor_enabled);
        $this->assertFalse($admin->must_change_password, 'The admin chose their own password, so is not asked to change it.');
        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $admin->password));
    }

    public function test_submitting_creates_a_department_row_for_each_service_selected(): void
    {
        $this->completeSteps(8);
        $this->submit();

        $departments = Department::where('facility_id', Facility::sole()->id)->orderBy('id')->get();

        $this->assertSame(
            [DepartmentType::Reception, DepartmentType::Consultation, DepartmentType::Laboratory],
            $departments->pluck('type')->all(),
        );
        $this->assertSame(['Reception', 'Consultation', 'Laboratory'], $departments->pluck('name')->all());
        $this->assertTrue($departments->every->is_active);
    }

    public function test_invited_staff_are_created_in_their_department_and_sent_their_temporary_password(): void
    {
        Notification::fake();
        $this->completeSteps(8);
        $this->submit();

        $doctor = User::where('email', 'otieno@upendo.test')->sole();

        $this->assertSame(UserRole::Doctor, $doctor->role);
        $this->assertTrue($doctor->must_change_password);
        $this->assertSame(DepartmentType::Consultation, $doctor->department->type);
        Notification::assertSentTo($doctor, StaffAccountCreated::class);
        Notification::assertSentToTimes($doctor, StaffAccountCreated::class, 1);
        Notification::assertNotSentTo(User::where('email', 'wanjiru@upendo.test')->sole(), StaffAccountCreated::class);
    }

    public function test_a_facility_open_24_hours_is_stored_without_opening_times(): void
    {
        $this->completeSteps(8, [4 => ['is_24hr' => '1', 'opens_at' => '', 'closes_at' => '']]);
        $this->submit();

        $facility = Facility::sole();

        $this->assertTrue($facility->is_24hr);
        $this->assertNull($facility->opens_at);
        $this->assertNull($facility->closes_at);
    }

    public function test_optional_screens_can_be_left_empty(): void
    {
        $this->completeSteps(8, [
            1 => ['ownership_type' => ''],
            2 => ['latitude' => '', 'longitude' => ''],
            3 => ['website' => ''],
            6 => ['staff' => []],
            7 => ['sms_sender_id' => ''],
        ]);
        $this->submit()->assertRedirect(route('facility.register.submitted'));

        $facility = Facility::sole();

        $this->assertNull($facility->ownership_type);
        $this->assertNull($facility->latitude);
        $this->assertNull($facility->sms_sender_id);
        $this->assertSame(1, User::count());
    }

    public function test_the_draft_is_cleared_so_a_second_submit_creates_nothing(): void
    {
        $this->completeSteps(8);
        $this->submit();

        $this->assertNull(session('facility_registration'));

        $this->submit()->assertRedirect(route('facility.register.step', 1));
        $this->assertSame(1, Facility::count());
    }

    public function test_missing_terms_or_signature_creates_nothing(): void
    {
        $this->completeSteps(8);

        $this->submit(['accepted_terms' => '', 'accepted_privacy' => '', 'signature' => ''])
            ->assertSessionHasErrors(['accepted_terms', 'accepted_privacy', 'signature']);

        $this->assertDatabaseCount('facilities', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_submitting_without_a_draft_sends_the_applicant_to_the_start(): void
    {
        $this->submit()
            ->assertRedirect(route('facility.register.step', 1))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('facilities', 0);
    }

    public function test_a_problem_on_an_earlier_screen_sends_the_applicant_back_to_it(): void
    {
        $this->completeSteps(8);
        User::factory()->systemAdmin()->create(['email' => 'wanjiru@upendo.test']);

        $this->submit()
            ->assertRedirect(route('facility.register.step', 5))
            ->assertSessionHasErrors('admin_email');

        $this->assertDatabaseCount('facilities', 0);
    }

    public function test_a_failure_part_way_through_leaves_no_partial_registration(): void
    {
        Notification::fake();
        $this->completeSteps(8);

        $this->mock(CreateStaffMember::class)
            ->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException('staff creation failed'));

        $this->withoutExceptionHandling();

        try {
            $this->submit();
            $this->fail('The failure should have propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('staff creation failed', $exception->getMessage());
        }

        $this->assertDatabaseCount('facilities', 0);
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('users', 0);
        Notification::assertNothingSent();
    }

    public function test_the_confirmation_screen_is_public(): void
    {
        $this->get(route('facility.register.submitted'))
            ->assertOk()
            ->assertSeeText('under review');
    }
}
