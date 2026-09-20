<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateFacilitySettingsRequest;
use App\Services\TrackingQrCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The facility admin's settings: how patients can reach the queue from their
 * phones, and the limits that keep that manageable.
 */
class SettingsController extends Controller
{
    public function edit(Request $request, TrackingQrCode $qrCode): View
    {
        $facility = $this->facility($request);

        return view('admin.settings', [
            'facility' => $facility,
            'selfCheckinUrl' => $facility->selfCheckinUrl(),
            // Printed and put up at the entrance, so people can scan it instead of queueing at the desk.
            'selfCheckinQr' => $facility->self_checkin_enabled ? $qrCode->dataUri($facility->selfCheckinUrl()) : null,
        ]);
    }

    public function update(UpdateFacilitySettingsRequest $request): RedirectResponse
    {
        $this->facility($request)->update($request->validated());

        return redirect()->route('admin.settings')->with('success', 'Settings saved.');
    }
}
