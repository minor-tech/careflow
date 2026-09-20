<?php

namespace App\Http\Controllers;

use App\Actions\SubmitRemoteRequest;
use App\Enums\RemoteRequestSource;
use App\Enums\RemoteRequestStatus;
use App\Exceptions\RemoteRequestRefused;
use App\Http\Requests\StoreRemoteRequestRequest;
use App\Models\Facility;
use App\Models\RemoteRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * A request to join today's queue from home, and the requester's own page for
 * following it. There is no login: the secret in the link is the key, made the
 * same way as a visit's tracking token.
 */
class RemoteRequestController extends Controller
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

    public function store(StoreRemoteRequestRequest $request, Facility $facility, SubmitRemoteRequest $submit): RedirectResponse
    {
        abort_unless($facility->isActive(), 404);

        // Filled in only by a bot: look as if it worked, and keep nothing.
        if (filled($request->input('website'))) {
            return redirect()->route('directory.show', $facility->slug);
        }

        try {
            $remoteRequest = $submit->handle($facility, $request->validated(), RemoteRequestSource::Remote);
        } catch (RemoteRequestRefused $exception) {
            return back()->withInput()->withErrors(['request' => $exception->getMessage()]);
        }

        return redirect()->route('remote.status', $remoteRequest->public_code);
    }

    /**
     * Where the requester follows their request. Once it is accepted this is
     * simply the ordinary live tracking page, so they are sent there: there is
     * no separate tracking screen for remote patients.
     */
    public function show(string $code): Response|RedirectResponse
    {
        $remoteRequest = RemoteRequest::where('public_code', $code)->with(['facility', 'visit'])->first();

        if ($remoteRequest === null) {
            return response()->view('tracking.unavailable', [], 404)->withHeaders(self::PRIVATE_HEADERS);
        }

        if ($remoteRequest->status === RemoteRequestStatus::Accepted && $remoteRequest->visit !== null) {
            return redirect()->route('tracking.show', $remoteRequest->visit->tracking_token);
        }

        $response = response()->view('remote.status', ['remoteRequest' => $remoteRequest])->withHeaders(self::PRIVATE_HEADERS);

        // Still waiting for a decision: look again on its own, so the page turns into their tracking page the moment it is accepted.
        return $remoteRequest->status->isOpen() ? $response->header('Refresh', '15') : $response;
    }

    /**
     * The requester withdraws a request nobody has looked at yet. Once staff
     * have decided there is nothing to withdraw.
     */
    public function cancel(string $code): RedirectResponse
    {
        $withdrawn = RemoteRequest::where('public_code', $code)
            ->where('status', RemoteRequestStatus::Pending)
            ->update(['status' => RemoteRequestStatus::Cancelled]);

        abort_if($withdrawn === 0 && ! RemoteRequest::where('public_code', $code)->exists(), 404);

        return redirect()->route('remote.status', $code);
    }
}
