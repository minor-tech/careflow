<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttemptTrackingEntryRequest;
use App\Models\Facility;
use App\Models\Visit;
use App\Support\QueueCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The typed way in to a patient's tracking page: queue code plus access PIN, for
 * when scanning the QR code doesn't work. A correct pair lands on the same page
 * as the link does; there is only one tracking view.
 *
 * A 4-digit PIN has 10,000 possibilities and queue codes are small sequential
 * numbers, so the limits below are what stand between a script and a stranger's
 * visit: a few misses per person, and a ceiling on misses per visit that a crowd
 * of different addresses can't get around.
 */
class PublicTrackController extends Controller
{
    /** Misses one address may make at one facility before it is shut out for a while. */
    private const MAX_ATTEMPTS_PER_ADDRESS = 5;

    private const ADDRESS_LOCKOUT_SECONDS = 600;

    /** Misses against one visit, from anyone, before its PIN stops being accepted for a while. */
    private const MAX_MISSES_PER_VISIT = 20;

    private const VISIT_LOCKOUT_SECONDS = 3600;

    /** The same words for every kind of miss, so the form never says which half was wrong. */
    private const NOT_RECOGNIZED = 'Queue code or PIN not recognized.';

    private const PUBLIC_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
    ];

    public function showForm(Facility $facility): Response
    {
        abort_unless($facility->isActive(), 404);

        return response()->view('tracking.entry', ['facility' => $facility])->withHeaders(self::PUBLIC_HEADERS);
    }

    public function attempt(AttemptTrackingEntryRequest $request, Facility $facility): RedirectResponse
    {
        abort_unless($facility->isActive(), 404);

        $addressKey = 'track-attempt:'.$facility->id.':'.$request->ip();

        if (RateLimiter::tooManyAttempts($addressKey, self::MAX_ATTEMPTS_PER_ADDRESS)) {
            return $this->failed($facility, 'pin', 'Too many attempts. Try again in a few minutes.', $request->input('queue_code'));
        }

        $queueNumber = QueueCode::parse($request->validated('queue_code'));

        if ($queueNumber === null) {
            return $this->failed($facility, 'queue_code', 'That doesn\'t look like a queue code. It looks like V027.', $request->input('queue_code'));
        }

        $pin = $request->validated('pin');

        // Today's visits only, by the clinic's calendar, and only ones still going:
        // the PIN stops opening the page once the visit is over.
        $visit = Visit::query()
            ->where('facility_id', $facility->id)
            ->where('queue_number', $queueNumber)
            ->registeredToday()
            ->unfinished()
            ->first();

        $visitKey = $visit?->accessPinMissesKey();
        $visitLockedOut = $visitKey !== null && RateLimiter::tooManyAttempts($visitKey, self::MAX_MISSES_PER_VISIT);

        if ($visit !== null && ! $visitLockedOut && $visit->access_pin_hash !== null && Hash::check($pin, $visit->access_pin_hash)) {
            RateLimiter::clear($addressKey);

            return redirect()->route('tracking.show', $visit->tracking_token);
        }

        // A miss takes as long as a hit would have, wherever it came from, so the
        // time a reply takes doesn't reveal which queue codes exist.
        if ($visit === null || $visitLockedOut || $visit->access_pin_hash === null) {
            Hash::make($pin);
        }

        RateLimiter::hit($addressKey, self::ADDRESS_LOCKOUT_SECONDS);

        if ($visitKey !== null) {
            RateLimiter::hit($visitKey, self::VISIT_LOCKOUT_SECONDS);
        }

        return $this->failed($facility, 'pin', self::NOT_RECOGNIZED, $request->input('queue_code'));
    }

    /**
     * Back to the form with a message. Only the queue code is sent back to be
     * pre-filled: the PIN is never echoed, not even into the session.
     */
    private function failed(Facility $facility, string $field, string $message, mixed $queueCode): RedirectResponse
    {
        return redirect()
            ->route('tracking.entry', $facility->slug)
            ->withErrors([$field => $message])
            ->withInput(['queue_code' => is_string($queueCode) ? $queueCode : '']);
    }
}
