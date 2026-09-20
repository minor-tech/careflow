<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\FeedbackIssue;
use App\Enums\VisitStatus;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Feedback;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the patient's tracking page does about feedback: ask once the visit is
 * complete, thank them afterwards, and never ask otherwise.
 */
class TrackingFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now(config('careflow.timezone'))->setTime(12, 0));

        $this->facility = Facility::factory()->create();
    }

    private function visit(VisitStatus $status, string $name = 'Brian Kamau'): Visit
    {
        return Visit::factory()->for($this->facility)->create([
            'department_id' => Department::factory()->for($this->facility)->create(['name' => 'Consultation'])->id,
            'patient_id' => Patient::factory()->for($this->facility)->create(['name' => $name])->id,
            'status' => $status,
        ]);
    }

    private function page(Visit $visit): string
    {
        return route('tracking.show', $visit->tracking_token);
    }

    public function test_a_completed_visit_that_has_not_been_rated_is_asked_how_it_was(): void
    {
        $visit = $this->visit(VisitStatus::Completed);

        $this->get($this->page($visit))
            ->assertOk()
            ->assertSeeTextInOrder(['Your visit is complete', 'Thank you, Brian.', 'How was your visit?', 'What went wrong?', 'Anything else?', 'Send feedback'])
            ->assertSee('action="'.route('tracking.feedback.store', $visit->tracking_token).'"', false);
    }

    public function test_it_offers_one_star_target_for_each_of_the_five_ratings(): void
    {
        $content = $this->get($this->page($this->visit(VisitStatus::Completed)))->getContent();

        foreach (range(1, 5) as $star) {
            $this->assertStringContainsString('id="rating-'.$star.'"', $content);
            $this->assertStringContainsString('value="'.$star.'"', $content);
        }
        $this->assertSame(5, substr_count($content, 'type="radio"'));
        // Each star is a 56px square: a comfortable thumb target.
        $this->assertSame(5, substr_count($content, 'h-14 w-14'));
    }

    public function test_the_categories_are_only_shown_for_a_rating_of_three_or_fewer(): void
    {
        $content = $this->get($this->page($this->visit(VisitStatus::Completed)))->getContent();

        $this->assertStringContainsString('this.rating >= 1 && this.rating <= 3', $content);
        $this->assertMatchesRegularExpression('/<fieldset x-show="unhappy" x-cloak/', $content);

        $this->assertSame(7, substr_count($content, 'name="issues[]"'));
        foreach (FeedbackIssue::values() as $issue) {
            $this->assertStringContainsString('value="'.$issue.'"', $content);
        }
    }

    public function test_the_journey_checklist_is_replaced_by_the_form(): void
    {
        $this->get($this->page($this->visit(VisitStatus::Completed)))
            ->assertDontSee('aria-label="Your visit so far"', false)
            ->assertDontSeeText('Further steps appear');
    }

    public function test_after_rating_the_page_says_thanks_and_never_shows_the_form_again(): void
    {
        $visit = $this->visit(VisitStatus::Completed);
        Feedback::factory()->create(['visit_id' => $visit->id]);

        $this->get($this->page($visit))
            ->assertOk()
            ->assertSeeText('Your visit is complete')
            ->assertSeeText('Thanks for your feedback.')
            ->assertDontSeeText('How was your visit?')
            ->assertDontSee('name="rating"', false)
            ->assertDontSee('aria-label="Your visit so far"', false);
    }

    public function test_rating_and_then_refreshing_shows_the_thanks_not_the_form(): void
    {
        $visit = $this->visit(VisitStatus::Completed);

        $this->post(route('tracking.feedback.store', $visit->tracking_token), ['rating' => 4])
            ->assertRedirect($this->page($visit));

        foreach (range(1, 2) as $ignored) {
            $this->get($this->page($visit))->assertSeeText('Thanks for your feedback.')->assertDontSee('name="rating"', false);
        }
    }

    public function test_trying_to_rate_twice_says_it_was_already_sent(): void
    {
        $visit = $this->visit(VisitStatus::Completed);
        $this->post(route('tracking.feedback.store', $visit->tracking_token), ['rating' => 4]);

        $this->followingRedirects()
            ->post(route('tracking.feedback.store', $visit->tracking_token), ['rating' => 1])
            ->assertSeeText('Thanks for your feedback.')
            ->assertSeeText('You have already sent feedback for this visit.');
    }

    public function test_the_thanks_that_follows_a_first_rating_has_no_already_sent_warning(): void
    {
        $visit = $this->visit(VisitStatus::Completed);

        $this->followingRedirects()
            ->post(route('tracking.feedback.store', $visit->tracking_token), ['rating' => 4])
            ->assertSeeText('Thanks for your feedback.')
            ->assertDontSeeText('already sent');
    }

    public function test_a_mistake_brings_the_form_back_with_the_message_and_what_was_typed(): void
    {
        $visit = $this->visit(VisitStatus::Completed);

        $this->followingRedirects()
            ->post(route('tracking.feedback.store', $visit->tracking_token), ['comment' => 'Long queue at the pharmacy', 'issues' => ['long_wait']])
            ->assertSeeText('Please choose a star rating.')
            ->assertSeeText('Long queue at the pharmacy')
            ->assertSee('value="long_wait"', false);
    }

    public function test_somebody_elses_rating_does_not_hide_the_form(): void
    {
        Feedback::factory()->create();
        $mine = $this->visit(VisitStatus::Completed);

        $this->get($this->page($mine))->assertSeeText('How was your visit?');
    }

    public function test_a_comment_is_never_shown_back_as_markup(): void
    {
        $visit = $this->visit(VisitStatus::Completed);

        $this->followingRedirects()
            ->post(route('tracking.feedback.store', $visit->tracking_token), ['comment' => '<script>alert(1)</script>'])
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    /**
     * @return array<string, array{VisitStatus}>
     */
    public static function visitsNotYetFinishedOrCancelled(): array
    {
        return [
            'waiting' => [VisitStatus::Waiting],
            'called' => [VisitStatus::Called],
            'in service' => [VisitStatus::InService],
            'cancelled' => [VisitStatus::Cancelled],
        ];
    }

    #[DataProvider('visitsNotYetFinishedOrCancelled')]
    public function test_a_visit_that_is_not_complete_is_never_asked_for_feedback(VisitStatus $status): void
    {
        $visit = $this->visit($status);

        $this->get($this->page($visit))
            ->assertOk()
            ->assertDontSeeText('How was your visit?')
            ->assertDontSee('name="rating"', false)
            ->assertDontSeeText('Thanks for your feedback');
        $this->getJson(route('tracking.status', $visit->tracking_token))
            ->assertJsonPath('html', fn (string $html) => ! str_contains($html, 'How was your visit?'));
    }

    public function test_the_form_appears_by_itself_when_staff_complete_the_visit_while_the_page_is_open(): void
    {
        $visit = $this->visit(VisitStatus::InService);
        $status = route('tracking.status', $visit->tracking_token);

        $this->getJson($status)->assertJsonPath('finished', false)->assertJsonPath('html', fn (string $html) => ! str_contains($html, 'How was your visit?'));

        $visit->update(['status' => VisitStatus::Completed]);

        $this->getJson($status)
            ->assertJsonPath('finished', true)
            ->assertJsonPath('html', fn (string $html) => str_contains($html, 'How was your visit?') && str_contains($html, 'name="rating"'));
    }

    public function test_the_polled_copy_shows_the_thanks_once_rated(): void
    {
        $visit = $this->visit(VisitStatus::Completed);
        Feedback::factory()->create(['visit_id' => $visit->id]);

        $this->getJson(route('tracking.status', $visit->tracking_token))
            ->assertJsonPath('html', fn (string $html) => str_contains($html, 'Thanks for your feedback.') && ! str_contains($html, 'How was your visit?'));
    }

    public function test_a_page_opened_after_the_visit_ended_is_never_polled_so_a_rating_in_progress_is_not_swapped_out(): void
    {
        $this->get($this->page($this->visit(VisitStatus::Completed)))->assertSee('finished: true', false);
        $this->get($this->page($this->visit(VisitStatus::Cancelled, 'Someone Else')))->assertSee('finished: true', false);
        $this->get($this->page($this->visit(VisitStatus::Waiting, 'Third Person')))->assertSee('finished: false', false);
    }
}
