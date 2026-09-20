<?php

namespace App\Http\Controllers;

use App\Enums\VisitStatus;
use App\Http\Requests\StorePublicFeedbackRequest;
use App\Models\Feedback;
use App\Models\Visit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;

/**
 * Takes a patient's rating of their visit from their tracking page. There is
 * no login: like the page itself, it is reached by the secret in the visit's
 * link, and it can only ever add to that one visit.
 */
class PublicFeedbackController extends Controller
{
    public function store(StorePublicFeedbackRequest $request, string $token): RedirectResponse
    {
        $visit = Visit::findByTrackingToken($token) ?? abort(404);

        // The form is only ever offered once a visit is complete (never for a cancelled one).
        abort_unless($visit->status === VisitStatus::Completed, 403);

        $back = redirect()->route('tracking.show', $token);

        if ($visit->feedback()->exists()) {
            return $back->with('feedback_notice', 'You have already sent feedback for this visit. Thank you.');
        }

        $rating = (int) $request->validated('rating');
        $issues = $rating <= Feedback::LOW_RATING ? array_values(array_unique($request->validated('issues') ?? [])) : [];

        try {
            Feedback::create([
                // Who and where come from the visit, never from what the browser sent.
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'facility_id' => $visit->facility_id,
                'rating' => $rating,
                'issues' => $issues === [] ? null : $issues,
                'comment' => $request->validated('comment'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two taps, or two tabs, at once: the database's one-per-visit rule caught the second.
            return $back->with('feedback_notice', 'You have already sent feedback for this visit. Thank you.');
        }

        return $back;
    }
}
