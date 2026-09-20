<?php

namespace App\Http\Controllers;

use App\Services\FacilityDailyStats;
use App\Services\FeedbackStats;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The facility admin's picture of today: patient counts, waits, and which
 * department is taking the longest, beside what patients have said lately,
 * so a complaint and a slow department can be seen together.
 */
class AnalyticsController extends Controller
{
    public function index(Request $request, FacilityDailyStats $stats, FeedbackStats $feedback): View
    {
        $facility = $this->facility($request);

        return view('analytics.index', [
            'summary' => $stats->summary($facility->id),
            'departments' => $stats->departmentPerformance($facility->id),
            'experience' => $feedback->breakdown($facility->id),
            'today' => now(config('careflow.timezone')),
        ]);
    }
}
