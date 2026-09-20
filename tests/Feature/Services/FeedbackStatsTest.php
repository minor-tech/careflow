<?php

namespace Tests\Feature\Services;

use App\Enums\FeedbackIssue;
use App\Models\Facility;
use App\Models\Feedback;
use App\Models\Visit;
use App\Services\FeedbackStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackStatsTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now(config('careflow.timezone'))->setTime(12, 0));

        $this->facility = Facility::factory()->create();
    }

    /**
     * @param  list<FeedbackIssue>  $issues
     */
    private function rated(int $rating, array $issues = [], ?Facility $facility = null, int $daysAgo = 0): Feedback
    {
        $facility ??= $this->facility;
        $visit = Visit::factory()->for($facility)->create();

        return Feedback::factory()->create([
            'visit_id' => $visit->id,
            'rating' => $rating,
            'issues' => $issues === [] ? null : array_map(fn (FeedbackIssue $issue) => $issue->value, $issues),
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    private function breakdown(?Facility $facility = null): array
    {
        return app(FeedbackStats::class)->breakdown(($facility ?? $this->facility)->id);
    }

    public function test_the_average_rating_is_to_one_decimal_place(): void
    {
        foreach ([5, 4, 4, 4, 3] as $rating) {
            $this->rated($rating);
        }

        $breakdown = $this->breakdown();

        $this->assertSame(4.0, $breakdown['avg_rating']);
        $this->assertSame(5, $breakdown['responses']);

        $this->rated(5);
        $this->assertSame(4.2, $this->breakdown()['avg_rating']);
    }

    public function test_with_no_feedback_there_is_no_average_rather_than_a_zero(): void
    {
        $breakdown = $this->breakdown();

        $this->assertNull($breakdown['avg_rating']);
        $this->assertSame(0, $breakdown['responses']);
        $this->assertSame(0, $breakdown['total_mentions']);
        $this->assertSame([], $breakdown['issues']);
    }

    public function test_only_the_last_30_days_count(): void
    {
        $this->rated(5);
        $this->rated(5, daysAgo: 29);
        $this->rated(1, [FeedbackIssue::Billing], daysAgo: 31);

        $breakdown = $this->breakdown();

        $this->assertSame(2, $breakdown['responses']);
        $this->assertSame(5.0, $breakdown['avg_rating']);
        $this->assertSame([], $breakdown['issues']);
    }

    public function test_only_this_facilitys_feedback_counts(): void
    {
        $this->rated(5);
        $this->rated(1, [FeedbackIssue::LongWait], Facility::factory()->create());

        $breakdown = $this->breakdown();

        $this->assertSame(5.0, $breakdown['avg_rating']);
        $this->assertSame([], $breakdown['issues']);
    }

    public function test_issues_are_shares_of_all_mentions_and_can_be_several_to_a_visit(): void
    {
        // 5 long waits, 3 billing, 2 doctor = 10 mentions, from 6 unhappy visits.
        $this->rated(1, [FeedbackIssue::LongWait, FeedbackIssue::Billing, FeedbackIssue::Doctor]);
        $this->rated(2, [FeedbackIssue::LongWait, FeedbackIssue::Billing, FeedbackIssue::Doctor]);
        $this->rated(2, [FeedbackIssue::LongWait, FeedbackIssue::Billing]);
        $this->rated(3, [FeedbackIssue::LongWait]);
        $this->rated(3, [FeedbackIssue::LongWait]);
        $this->rated(1);

        $breakdown = $this->breakdown();

        $this->assertSame(10, $breakdown['total_mentions']);
        $this->assertSame(6, $breakdown['low_rating_responses']);
        $this->assertSame(
            [['Long wait', 5, 50], ['Billing', 3, 30], ['Doctor', 2, 20]],
            array_map(fn (array $row) => [$row['label'], $row['mentions'], $row['percent']], $breakdown['issues']),
        );
        $this->assertSame(FeedbackIssue::LongWait, $breakdown['issues'][0]['issue']);
    }

    public function test_the_shares_are_taken_only_from_visits_rated_three_or_fewer(): void
    {
        $this->rated(2, [FeedbackIssue::Staff]);
        // Should never exist (the app drops them), but if one did it must not skew the picture.
        $this->rated(5, [FeedbackIssue::Billing]);

        $breakdown = $this->breakdown();

        $this->assertSame(1, $breakdown['total_mentions']);
        $this->assertSame(['Staff'], array_column($breakdown['issues'], 'label'));
    }

    public function test_categories_nobody_mentioned_are_left_out_and_ties_follow_the_list_order(): void
    {
        $this->rated(1, [FeedbackIssue::Pharmacy, FeedbackIssue::Staff, FeedbackIssue::Other]);

        $this->assertSame(['Staff', 'Pharmacy', 'Other'], array_column($this->breakdown()['issues'], 'label'));
    }

    public function test_percentages_are_rounded_to_whole_numbers(): void
    {
        $this->rated(1, [FeedbackIssue::LongWait, FeedbackIssue::Billing, FeedbackIssue::Doctor]);

        $this->assertSame([33, 33, 33], array_column($this->breakdown()['issues'], 'percent'));
    }

    public function test_unhappy_visits_that_named_nothing_leave_the_issues_empty(): void
    {
        $this->rated(1);
        $this->rated(2);

        $breakdown = $this->breakdown();

        $this->assertSame(2, $breakdown['low_rating_responses']);
        $this->assertSame([], $breakdown['issues']);
        $this->assertSame(0, $breakdown['total_mentions']);
    }

    public function test_a_category_that_no_longer_exists_is_ignored_rather_than_breaking_the_report(): void
    {
        $this->rated(1)->update(['issues' => ['long_wait', 'retired_category']]);

        $breakdown = $this->breakdown();

        $this->assertSame(1, $breakdown['total_mentions']);
        $this->assertSame(['Long wait'], array_column($breakdown['issues'], 'label'));
    }
}
