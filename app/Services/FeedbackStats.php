<?php

namespace App\Services;

use App\Enums\FeedbackIssue;
use App\Models\Feedback;
use Illuminate\Database\Eloquent\Builder;

/**
 * What patients have said about a facility over the last 30 days: the average
 * rating, and what the unhappy ones said went wrong.
 */
class FeedbackStats
{
    public const WINDOW_DAYS = 30;

    /**
     * Issues are counted only from ratings of 3 stars or fewer (the only ones
     * asked), and a patient may name several, so the percentages are shares of
     * all the issues mentioned: they add up to 100 across the list, and are
     * not a share of patients.
     *
     * @return array{
     *     responses: int,
     *     avg_rating: float|null,
     *     low_rating_responses: int,
     *     total_mentions: int,
     *     issues: list<array{issue: FeedbackIssue, label: string, mentions: int, percent: int}>
     * }
     */
    public function breakdown(int $facilityId): array
    {
        $recent = fn (): Builder => Feedback::query()
            ->where('facility_id', $facilityId)
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS));

        $average = $recent()->avg('rating');

        $low = $recent()->where('rating', '<=', Feedback::LOW_RATING);

        $mentions = [];
        foreach ($low->pluck('issues') as $issues) {
            foreach ($issues ?? [] as $value) {
                $issue = FeedbackIssue::tryFrom($value);

                if ($issue !== null) {
                    $mentions[$issue->value] = ($mentions[$issue->value] ?? 0) + 1;
                }
            }
        }

        $total = array_sum($mentions);

        $rows = [];
        foreach (FeedbackIssue::cases() as $position => $issue) {
            if (isset($mentions[$issue->value])) {
                $rows[] = [
                    'issue' => $issue,
                    'label' => $issue->label(),
                    'mentions' => $mentions[$issue->value],
                    'percent' => (int) round($mentions[$issue->value] / $total * 100),
                    'position' => $position,
                ];
            }
        }

        // Most mentioned first; ties in the order the categories are listed.
        usort($rows, fn (array $a, array $b) => [$b['mentions'], $a['position']] <=> [$a['mentions'], $b['position']]);

        return [
            'responses' => $recent()->count(),
            'avg_rating' => $average === null ? null : round((float) $average, 1),
            'low_rating_responses' => $recent()->where('rating', '<=', Feedback::LOW_RATING)->count(),
            'total_mentions' => $total,
            'issues' => array_map(fn (array $row) => [
                'issue' => $row['issue'],
                'label' => $row['label'],
                'mentions' => $row['mentions'],
                'percent' => $row['percent'],
            ], $rows),
        ];
    }
}
