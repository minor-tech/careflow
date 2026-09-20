<?php

namespace Tests\Feature\Actions;

use App\Actions\ReviewFacility;
use App\Enums\FacilityStatus;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\FacilityApproved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class ReviewFacilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_first_of_two_reviews_takes_effect_and_only_one_email_is_sent(): void
    {
        Notification::fake();
        $facility = Facility::factory()->pendingReview()->create();
        $admin = User::factory()->for($facility)->create();
        $reviewer = User::factory()->systemAdmin()->create();
        $review = app(ReviewFacility::class);

        // The second reviewer is holding a stale copy that still says pending.
        $stale = Facility::find($facility->id);

        $this->assertTrue($review->approve($facility, $reviewer));
        $this->assertNull($review->reject($stale, User::factory()->systemAdmin()->create(), 'Arrived a moment too late.'));

        $facility->refresh();
        $this->assertSame(FacilityStatus::Active, $facility->status);
        $this->assertNull($facility->rejection_reason);
        $this->assertSame($reviewer->id, $facility->reviewed_by);
        Notification::assertSentToTimes($admin, FacilityApproved::class, 1);
        Notification::assertCount(1);
    }

    public function test_a_failed_email_is_reported_but_does_not_undo_the_decision(): void
    {
        Exceptions::fake();
        $facility = Facility::factory()->pendingReview()->create();
        User::factory()->for($facility)->create();

        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('mail server down'));

        $result = app(ReviewFacility::class)->approve($facility, User::factory()->systemAdmin()->create());

        $this->assertFalse($result);
        $this->assertSame(FacilityStatus::Active, $facility->fresh()->status);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'mail server down');
    }

    public function test_a_facility_with_no_admin_account_is_still_reviewed(): void
    {
        Notification::fake();
        $facility = Facility::factory()->pendingReview()->create();

        $this->assertTrue(app(ReviewFacility::class)->approve($facility, User::factory()->systemAdmin()->create()));

        $this->assertSame(FacilityStatus::Active, $facility->fresh()->status);
        Notification::assertNothingSent();
    }
}
