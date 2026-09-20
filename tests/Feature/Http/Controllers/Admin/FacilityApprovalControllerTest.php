<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Enums\FacilityStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\FacilityApproved;
use App\Notifications\FacilityRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RegistersFacilities;
use Tests\TestCase;

class FacilityApprovalControllerTest extends TestCase
{
    use RefreshDatabase;
    use RegistersFacilities;

    private User $systemAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->systemAdmin = User::factory()->systemAdmin()->create();
    }

    /**
     * @return array<string, array{FacilityStatus}>
     */
    public static function alreadyReviewedStatuses(): array
    {
        return [
            'active' => [FacilityStatus::Active],
            'suspended' => [FacilityStatus::Suspended],
            'rejected' => [FacilityStatus::Rejected],
        ];
    }

    public function test_the_queue_lists_only_pending_facilities_newest_first_with_their_admin(): void
    {
        $older = Facility::factory()->pendingReview()->create(['name' => 'Older Clinic', 'created_at' => now()->subDays(2)]);
        $newer = Facility::factory()->pendingReview()->create(['name' => 'Newer Clinic', 'county' => 'Kiambu', 'license_number' => 'KMPDC-777']);
        User::factory()->for($older)->create(['name' => 'Olive Owner', 'email' => 'olive@older.test']);
        User::factory()->for($newer)->create(['name' => 'Nina Newer', 'email' => 'nina@newer.test']);
        Facility::factory()->create(['name' => 'Already Active Clinic']);
        Facility::factory()->suspended()->create(['name' => 'Suspended Clinic']);
        Facility::factory()->rejected()->create(['name' => 'Rejected Clinic']);

        $this->actingAs($this->systemAdmin)
            ->get(route('system.facilities.pending'))
            ->assertOk()
            ->assertSeeInOrder(['Newer Clinic', 'Older Clinic'])
            ->assertSeeText('Kiambu')
            ->assertSeeText('KMPDC-777')
            ->assertSeeText('Nina Newer')
            ->assertSeeText('nina@newer.test')
            ->assertSeeText('olive@older.test')
            ->assertSee('cf-dot--wait', false)
            ->assertDontSeeText('Already Active Clinic')
            ->assertDontSeeText('Suspended Clinic')
            ->assertDontSeeText('Rejected Clinic');
    }

    public function test_the_queue_says_so_when_nothing_is_waiting(): void
    {
        $this->actingAs($this->systemAdmin)
            ->get(route('system.facilities.pending'))
            ->assertOk()
            ->assertSeeText('No facilities are waiting for review.');
    }

    public function test_the_queue_offers_review_links_not_approve_or_reject_buttons(): void
    {
        $facility = Facility::factory()->pendingReview()->create();

        $this->actingAs($this->systemAdmin)
            ->get(route('system.facilities.pending'))
            ->assertSee(route('system.facilities.show', $facility), false)
            ->assertDontSee(route('system.facilities.approve', $facility), false)
            ->assertDontSee(route('system.facilities.reject', $facility), false);
    }

    public function test_a_facility_registered_through_the_wizard_appears_in_the_queue_as_pending_review(): void
    {
        $this->completeSteps(8);
        $this->post(route('facility.register.store'), $this->stepPayload(9))->assertRedirect(route('facility.register.submitted'));

        $this->assertSame(FacilityStatus::PendingReview, Facility::sole()->status);

        $this->actingAs($this->systemAdmin)
            ->get(route('system.facilities.pending'))
            ->assertSeeText('Upendo Health Centre')
            ->assertSeeText('KMPDC-123456')
            ->assertSeeText('Wanjiru Kamau')
            ->assertSeeText('wanjiru@upendo.test');
    }

    public function test_the_detail_page_shows_everything_needed_to_verify_the_registration(): void
    {
        $facility = Facility::factory()->pendingReview()->create([
            'name' => 'Upendo Health Centre',
            'license_number' => 'KMPDC-123456',
            'sub_county' => 'Westlands',
            'address' => 'Opposite Sarit Centre',
            'latitude' => -1.2612345,
            'longitude' => 36.8023456,
            'phone' => '0712 345 678',
            'operating_days' => ['mon', 'sat'],
            'opens_at' => '08:00:00',
            'closes_at' => '17:00:00',
            'doctors_count' => 4,
            'odpc_registration_no' => 'ODPC-99',
            'signature_name' => 'Wanjiru Kamau',
        ]);
        Department::factory()->for($facility)->create(['name' => 'Laboratory']);
        User::factory()->for($facility)->create(['name' => 'Wanjiru Kamau', 'title' => 'Facility Manager', 'email' => 'wanjiru@upendo.test', 'phone' => '0722 000 111']);
        User::factory()->for($facility)->doctor()->create();

        $this->actingAs($this->systemAdmin)
            ->get(route('system.facilities.show', $facility))
            ->assertOk()
            ->assertSeeText('KMPDC-123456')
            ->assertSeeText('Westlands')
            ->assertSeeText('Opposite Sarit Centre')
            ->assertSeeText('Mon, Sat')
            ->assertSeeText('8:00 AM to 5:00 PM')
            ->assertSeeText('Laboratory')
            ->assertSeeText('ODPC-99')
            ->assertSeeText('Signed by')
            ->assertSeeText('Facility Manager')
            ->assertSeeText('wanjiru@upendo.test')
            ->assertSeeText('0722 000 111')
            ->assertSeeText('Data Processing Agreement accepted')
            ->assertSee('https://www.openstreetmap.org/?mlat=-1.2612345&amp;mlon=36.8023456', false);
    }

    public function test_the_detail_page_marks_missing_optional_details_as_not_provided(): void
    {
        $facility = Facility::factory()->pendingReview()->create(['website' => null, 'latitude' => null, 'longitude' => null]);

        $this->actingAs($this->systemAdmin)
            ->get(route('system.facilities.show', $facility))
            ->assertSeeText('Not provided')
            ->assertDontSee('openstreetmap.org', false);
    }

    public function test_the_decision_buttons_appear_only_while_a_registration_is_pending(): void
    {
        $pending = Facility::factory()->pendingReview()->create();
        $active = Facility::factory()->create();

        $this->actingAs($this->systemAdmin)
            ->get(route('system.facilities.show', $pending))
            ->assertSeeText('Approve facility')
            ->assertSee(route('system.facilities.reject', $pending), false);

        $this->actingAs($this->systemAdmin)
            ->get(route('system.facilities.show', $active))
            ->assertOk()
            ->assertDontSeeText('Approve facility')
            ->assertDontSee(route('system.facilities.approve', $active), false);
    }

    public function test_a_reviewed_facility_shows_who_reviewed_it_and_why_it_was_rejected(): void
    {
        $facility = Facility::factory()->rejected('The license number does not match the register.')->create();
        $facility->forceFill(['reviewed_by' => $this->systemAdmin->id])->save();

        $this->actingAs($this->systemAdmin)
            ->get(route('system.facilities.show', $facility))
            ->assertSeeText('Rejected')
            ->assertSeeText('by '.$this->systemAdmin->name)
            ->assertSeeText('The license number does not match the register.');
    }

    public function test_a_facility_that_does_not_exist_is_a_404(): void
    {
        $this->actingAs($this->systemAdmin)->get(route('system.facilities.show', 999))->assertNotFound();
    }

    public function test_approving_activates_the_facility_and_records_who_and_when(): void
    {
        Notification::fake();
        $facility = Facility::factory()->pendingReview()->create(['name' => 'Upendo Clinic']);

        $this->actingAs($this->systemAdmin)
            ->post(route('system.facilities.approve', $facility))
            ->assertRedirect(route('system.facilities.pending'))
            ->assertSessionHas('success', 'Upendo Clinic is now active. Its admin has been emailed.');

        $facility->refresh();
        $this->assertSame(FacilityStatus::Active, $facility->status);
        $this->assertNotNull($facility->reviewed_at);
        $this->assertSame($this->systemAdmin->id, $facility->reviewed_by);
        $this->assertNull($facility->rejection_reason);
    }

    public function test_approving_emails_the_facilitys_admin_and_nobody_else(): void
    {
        Notification::fake();
        $facility = Facility::factory()->pendingReview()->create();
        $admin = User::factory()->for($facility)->create();
        $receptionist = User::factory()->for($facility)->receptionist()->create();
        $bystander = User::factory()->for(Facility::factory()->pendingReview())->create();

        $this->actingAs($this->systemAdmin)->post(route('system.facilities.approve', $facility));

        Notification::assertSentTo($admin, FacilityApproved::class, fn ($notification, array $channels) => $channels === ['mail']);
        Notification::assertNotSentTo($receptionist, FacilityApproved::class);
        Notification::assertNotSentTo($bystander, FacilityApproved::class);
        Notification::assertSentTimes(FacilityApproved::class, 1);
    }

    public function test_after_approval_the_facilitys_admin_and_staff_get_past_the_pending_check(): void
    {
        Notification::fake();
        $facility = Facility::factory()->pendingReview()->create();
        $admin = User::factory()->for($facility)->create();
        $receptionist = User::factory()->for($facility)->receptionist()->create();

        $this->actingAs($admin)->get(route('admin.facility'))->assertRedirect(route('facility.status'));

        $this->actingAs($this->systemAdmin)->post(route('system.facilities.approve', $facility));

        // A real request loads the user afresh; the in-memory ones still hold the stale pending facility.
        $this->actingAs($admin->fresh())->get(route('admin.facility'))->assertOk();
        $this->actingAs($receptionist->fresh())->get(route('queue.index'))->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reviewActions(): array
    {
        return ['approve' => ['approve'], 'reject' => ['reject']];
    }

    #[DataProvider('alreadyReviewedStatuses')]
    public function test_approving_a_facility_that_is_not_pending_changes_and_sends_nothing(FacilityStatus $status): void
    {
        Notification::fake();
        $facility = Facility::factory()->create(['status' => $status]);
        User::factory()->for($facility)->create();

        $this->actingAs($this->systemAdmin)
            ->post(route('system.facilities.approve', $facility))
            ->assertRedirect(route('system.facilities.show', $facility))
            ->assertSessionHasErrors('review');

        $this->assertSame($status, $facility->fresh()->status);
        $this->assertNull($facility->fresh()->reviewed_by);
        Notification::assertNothingSent();
    }

    #[DataProvider('alreadyReviewedStatuses')]
    public function test_rejecting_a_facility_that_is_not_pending_changes_and_sends_nothing(FacilityStatus $status): void
    {
        Notification::fake();
        $facility = Facility::factory()->create(['status' => $status]);
        User::factory()->for($facility)->create();

        $this->actingAs($this->systemAdmin)
            ->post(route('system.facilities.reject', $facility), ['reason' => 'Changed our mind about this one.'])
            ->assertRedirect(route('system.facilities.show', $facility))
            ->assertSessionHasErrors('review');

        $this->assertSame($status, $facility->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_rejecting_records_the_reason_and_marks_the_facility_rejected_not_suspended(): void
    {
        Notification::fake();
        $facility = Facility::factory()->pendingReview()->create(['name' => 'Upendo Clinic']);

        $this->actingAs($this->systemAdmin)
            ->post(route('system.facilities.reject', $facility), ['reason' => 'The license number does not match the register.'])
            ->assertRedirect(route('system.facilities.pending'))
            ->assertSessionHas('success', 'Upendo Clinic was rejected. Its admin has been emailed the reason.');

        $facility->refresh();
        $this->assertSame(FacilityStatus::Rejected, $facility->status);
        $this->assertSame('The license number does not match the register.', $facility->rejection_reason);
        $this->assertSame($this->systemAdmin->id, $facility->reviewed_by);
        $this->assertNotNull($facility->reviewed_at);
    }

    public function test_rejecting_emails_the_reason_to_the_facilitys_admin(): void
    {
        Notification::fake();
        $facility = Facility::factory()->pendingReview()->create();
        $admin = User::factory()->for($facility)->create();
        $receptionist = User::factory()->for($facility)->receptionist()->create();

        $this->actingAs($this->systemAdmin)
            ->post(route('system.facilities.reject', $facility), ['reason' => 'The license number does not match the register.']);

        Notification::assertSentTo($admin, FacilityRejected::class, function (FacilityRejected $notification, array $channels) use ($admin) {
            return $channels === ['mail']
                && str_contains(implode("\n", $notification->toMail($admin)->introLines), 'The license number does not match the register.');
        });
        Notification::assertNotSentTo($receptionist, FacilityRejected::class);
        Notification::assertNotSentTo($admin, FacilityApproved::class);
    }

    public function test_rejecting_needs_a_real_reason_and_changes_nothing_without_one(): void
    {
        Notification::fake();
        $facility = Facility::factory()->pendingReview()->create();
        User::factory()->for($facility)->create();

        $this->actingAs($this->systemAdmin)
            ->post(route('system.facilities.reject', $facility), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->systemAdmin)
            ->post(route('system.facilities.reject', $facility), ['reason' => 'no'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(FacilityStatus::PendingReview, $facility->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_a_rejected_facilitys_admin_is_kept_out_and_told_why(): void
    {
        Notification::fake();
        $facility = Facility::factory()->pendingReview()->create();
        $admin = User::factory()->for($facility)->create();
        $receptionist = User::factory()->for($facility)->receptionist()->create();

        $this->actingAs($this->systemAdmin)
            ->post(route('system.facilities.reject', $facility), ['reason' => 'The license number does not match the register.']);

        $this->actingAs($admin)
            ->get(route('admin.facility'))
            ->assertRedirect(route('facility.status'));

        $this->actingAs($admin)
            ->get(route('facility.status'))
            ->assertSeeText('Registration not approved')
            ->assertSeeText('The license number does not match the register.');

        $this->actingAs($receptionist)
            ->get(route('queue.index'))
            ->assertRedirect(route('login'));
    }
}
