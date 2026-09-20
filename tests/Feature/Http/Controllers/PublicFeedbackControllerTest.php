<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\VisitStatus;
use App\Models\Facility;
use App\Models\Feedback;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicFeedbackControllerTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();

        // Midday, so the visit is comfortably "today" in the clinic's calendar.
        $this->travelTo(now(config('careflow.timezone'))->setTime(12, 0));

        $this->facility = Facility::factory()->create();
        $this->visit = $this->completedVisit();
    }

    private function completedVisit(VisitStatus $status = VisitStatus::Completed): Visit
    {
        return Visit::factory()->for($this->facility)->create([
            'patient_id' => Patient::factory()->for($this->facility)->create()->id,
            'status' => $status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(array $data, ?Visit $visit = null): TestResponse
    {
        return $this->post(route('tracking.feedback.store', ($visit ?? $this->visit)->tracking_token), $data);
    }

    private function back(?Visit $visit = null): string
    {
        return route('tracking.show', ($visit ?? $this->visit)->tracking_token);
    }

    public function test_a_patient_can_rate_their_completed_visit_without_logging_in(): void
    {
        $this->assertGuest();

        $this->send(['rating' => 5])->assertRedirect($this->back())->assertSessionHasNoErrors();

        $feedback = Feedback::sole();
        $this->assertSame(5, $feedback->rating);
        $this->assertNull($feedback->issues);
        $this->assertNull($feedback->comment);
    }

    public function test_the_feedback_belongs_to_the_visit_and_its_patient_and_facility_whatever_the_browser_says(): void
    {
        $other = $this->completedVisit();

        $this->send(['rating' => 4, 'visit_id' => $other->id, 'patient_id' => $other->patient_id, 'facility_id' => Facility::factory()->create()->id]);

        $feedback = Feedback::sole();
        $this->assertSame($this->visit->id, $feedback->visit_id);
        $this->assertSame($this->visit->patient_id, $feedback->patient_id);
        $this->assertSame($this->facility->id, $feedback->facility_id);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function everyStarRating(): array
    {
        return ['one star' => [1], 'two stars' => [2], 'three stars' => [3], 'four stars' => [4], 'five stars' => [5]];
    }

    #[DataProvider('everyStarRating')]
    public function test_every_rating_from_one_to_five_is_accepted(int $rating): void
    {
        $this->send(['rating' => $rating])->assertSessionHasNoErrors();

        $this->assertSame($rating, Feedback::sole()->rating);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notARating(): array
    {
        return ['missing' => [null], 'zero' => [0], 'six' => [6], 'negative' => [-1], 'text' => ['great'], 'a fraction' => [3.5], 'empty' => ['']];
    }

    #[DataProvider('notARating')]
    public function test_anything_but_a_whole_number_of_stars_from_one_to_five_is_refused(mixed $rating): void
    {
        $this->send(['rating' => $rating])
            ->assertRedirect($this->back())
            ->assertSessionHasErrors(['rating' => 'Please choose a star rating.']);

        $this->assertSame(0, Feedback::count());
    }

    public function test_an_unhappy_patient_can_say_what_went_wrong_and_add_a_comment(): void
    {
        $this->send(['rating' => 2, 'issues' => ['long_wait', 'billing'], 'comment' => 'Waited two hours.'])->assertSessionHasNoErrors();

        $feedback = Feedback::sole();
        $this->assertSame(['long_wait', 'billing'], $feedback->issues);
        $this->assertSame('Waited two hours.', $feedback->comment);
    }

    public function test_three_stars_is_still_asked_what_went_wrong(): void
    {
        $this->send(['rating' => 3, 'issues' => ['staff']]);

        $this->assertSame(['staff'], Feedback::sole()->issues);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function contentRatings(): array
    {
        return ['four stars' => [4], 'five stars' => [5]];
    }

    #[DataProvider('contentRatings')]
    public function test_categories_sent_with_four_or_five_stars_are_quietly_dropped(int $rating): void
    {
        $this->send(['rating' => $rating, 'issues' => ['long_wait', 'billing', 'not-even-a-category']])->assertSessionHasNoErrors();

        $this->assertNull(Feedback::sole()->issues);
    }

    public function test_a_category_that_does_not_exist_is_refused_for_an_unhappy_rating(): void
    {
        $this->send(['rating' => 2, 'issues' => ['long_wait', 'made_up']])->assertSessionHasErrors('issues.1');

        $this->assertSame(0, Feedback::count());
    }

    public function test_the_same_category_ticked_twice_is_kept_once(): void
    {
        $this->send(['rating' => 1, 'issues' => ['doctor', 'doctor']]);

        $this->assertSame(['doctor'], Feedback::sole()->issues);
    }

    public function test_an_unhappy_rating_with_no_categories_stores_none(): void
    {
        $this->send(['rating' => 2, 'issues' => []]);

        $this->assertNull(Feedback::sole()->issues);
    }

    public function test_a_blank_comment_is_stored_as_nothing_and_a_very_long_one_is_refused(): void
    {
        $this->send(['rating' => 4, 'comment' => '   '])->assertSessionHasNoErrors();
        $this->assertNull(Feedback::sole()->comment);

        $other = $this->completedVisit();
        $this->send(['rating' => 4, 'comment' => str_repeat('a', 1001)], $other)->assertSessionHasErrors('comment');
        $this->assertSame(1, Feedback::count());
    }

    public function test_a_comment_is_stored_as_typed_and_not_treated_as_markup_until_shown(): void
    {
        $this->send(['rating' => 2, 'comment' => '<script>alert(1)</script> & "quoted"']);

        $this->assertSame('<script>alert(1)</script> & "quoted"', Feedback::sole()->comment);
    }

    public function test_sending_feedback_twice_is_refused_cleanly_and_the_first_answer_stands(): void
    {
        $this->send(['rating' => 5]);

        $this->send(['rating' => 1, 'issues' => ['staff']])
            ->assertRedirect($this->back())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('feedback_notice', 'You have already sent feedback for this visit. Thank you.');

        $feedback = Feedback::sole();
        $this->assertSame(5, $feedback->rating);
        $this->assertNull($feedback->issues);
    }

    public function test_two_answers_arriving_at_the_same_instant_are_caught_by_the_database_not_a_crash(): void
    {
        // Just before this answer is saved, "another tab" gets its own in.
        Feedback::creating(function (Feedback $feedback): void {
            DB::table('feedback')->insert([
                'visit_id' => $feedback->visit_id,
                'patient_id' => $feedback->patient_id,
                'facility_id' => $feedback->facility_id,
                'rating' => 5,
                'created_at' => now(),
            ]);
        });

        $this->send(['rating' => 1])
            ->assertRedirect($this->back())
            ->assertSessionHas('feedback_notice', 'You have already sent feedback for this visit. Thank you.');

        $this->assertSame(1, Feedback::count());
        $this->assertSame(5, Feedback::sole()->rating);
    }

    /**
     * @return array<string, array{VisitStatus}>
     */
    public static function visitsNotFinished(): array
    {
        return [
            'waiting' => [VisitStatus::Waiting],
            'called' => [VisitStatus::Called],
            'in service' => [VisitStatus::InService],
            'cancelled' => [VisitStatus::Cancelled],
        ];
    }

    #[DataProvider('visitsNotFinished')]
    public function test_a_visit_that_did_not_finish_cannot_be_rated(VisitStatus $status): void
    {
        $visit = $this->completedVisit($status);

        $this->send(['rating' => 5], $visit)->assertForbidden();

        $this->assertSame(0, Feedback::count());
    }

    public function test_an_unknown_or_malformed_link_is_not_found(): void
    {
        $this->post(route('tracking.feedback.store', 'abcdefghjkmnpq'), ['rating' => 5])->assertNotFound();
        $this->post('/t/short/feedback', ['rating' => 5])->assertNotFound();

        $this->assertSame(0, Feedback::count());
    }

    public function test_the_link_only_takes_feedback_on_the_visits_own_day(): void
    {
        $this->travelTo(now(config('careflow.timezone'))->addDay()->setTime(9, 0));

        $this->send(['rating' => 5])->assertNotFound();

        $this->assertSame(0, Feedback::count());
    }

    public function test_the_route_is_rate_limited_per_link(): void
    {
        $other = $this->completedVisit();

        foreach (range(1, 30) as $ignored) {
            $this->send(['rating' => 0]);
        }

        $this->send(['rating' => 5])->assertTooManyRequests();
        $this->send(['rating' => 5], $other)->assertRedirect($this->back($other));
    }
}
