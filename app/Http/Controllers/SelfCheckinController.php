<?php

namespace App\Http\Controllers;

use App\Actions\SubmitRemoteRequest;
use App\Enums\RemoteRequestSource;
use App\Exceptions\RemoteRequestRefused;
use App\Http\Requests\StoreSelfCheckinRequest;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Someone already in the building registers on their own phone, from a QR code
 * or a short address, instead of queueing at the reception counter. It makes
 * the same kind of request as one from home, marked so staff can tell them
 * apart; staff still confirm it, which they can do at a glance because the
 * person is standing there.
 */
class SelfCheckinController extends Controller
{
    private const PRIVATE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
    ];

    public function form(Facility $facility): Response
    {
        abort_unless($facility->isActive(), 404);

        if (! $facility->self_checkin_enabled) {
            return response()->view('checkin.unavailable', ['facility' => $facility])->withHeaders(self::PRIVATE_HEADERS);
        }

        $departmentIds = Department::where('facility_id', $facility->id)->where('is_active', true)->pluck('id');

        return response()->view('checkin.form', [
            'facility' => $facility,
            'services' => $facility->remote_queue_allow_service_choice
                ? Service::whereIn('department_id', $departmentIds)->orderBy('name')->pluck('name', 'id')->all()
                : [],
        ])->withHeaders(self::PRIVATE_HEADERS);
    }

    public function submit(StoreSelfCheckinRequest $request, Facility $facility, SubmitRemoteRequest $submit): RedirectResponse
    {
        abort_unless($facility->isActive(), 404);

        // Filled in only by a bot: look as if it worked, and keep nothing.
        if (filled($request->input('website'))) {
            return redirect()->route('checkin.form', $facility->slug);
        }

        try {
            $remoteRequest = $submit->handle($facility, $request->validated(), RemoteRequestSource::SelfCheckin);
        } catch (RemoteRequestRefused $exception) {
            return back()->withInput()->withErrors(['request' => $exception->getMessage()]);
        }

        return redirect()->route('remote.status', $remoteRequest->public_code);
    }
}
