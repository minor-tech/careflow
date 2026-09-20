<?php

namespace App\Http\Controllers;

use App\Models\Visit;
use App\Services\RemoteArrival;
use App\Services\VisitTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * The patient's own page for following their visit, reached from the link in
 * their SMS or the QR code on their slip. There is no login: the token in the
 * address is the key, and the page only ever shows that one visit.
 */
class TrackingController extends Controller
{
    /**
     * A page about one person's health visit: nothing keeps a copy of it, and
     * the address isn't passed on to any site it links to.
     */
    private const PRIVATE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
    ];

    public function __construct(private VisitTracker $tracker) {}

    public function show(string $token): Response
    {
        $visit = Visit::findByTrackingToken($token);

        if ($visit === null) {
            return response()->view('tracking.unavailable', [], 404)->withHeaders(self::PRIVATE_HEADERS);
        }

        return response()->view('tracking.show', [
            'snapshot' => $this->tracker->snapshot($visit),
            'token' => $token,
            'pollSeconds' => (int) config('careflow.tracking.poll_seconds'),
        ])->withHeaders(self::PRIVATE_HEADERS);
    }

    /**
     * "I've arrived", from a patient accepted from home. It is only a claim:
     * it shows staff they say they're here, and changes nothing about the queue
     * until staff check them in. Doing it twice, or when it means nothing (not
     * awaiting arrival), does nothing and lands them on the same page.
     */
    public function arrived(string $token, RemoteArrival $arrival): RedirectResponse
    {
        $visit = Visit::findByTrackingToken($token);

        abort_if($visit === null, 404);

        $arrival->signal($visit);

        return redirect()->route('tracking.show', $token);
    }

    /**
     * What the open page asks for every few seconds: the page's live part,
     * already rendered, and whether there is any point asking again.
     */
    public function status(string $token): JsonResponse
    {
        $visit = Visit::findByTrackingToken($token);

        if ($visit === null) {
            return response()->json(['finished' => true], 404)->withHeaders(self::PRIVATE_HEADERS);
        }

        $snapshot = $this->tracker->snapshot($visit);

        return response()->json([
            'html' => view('tracking._live', ['snapshot' => $snapshot])->render(),
            'finished' => ! $snapshot->open,
        ])->withHeaders(self::PRIVATE_HEADERS);
    }
}
