<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ReviewFacility;
use App\Enums\FacilityStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectFacilityRequest;
use App\Models\Facility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The system admin's queue: verify a facility's registration, then approve or
 * reject it. Routes are limited to the system_admin role.
 */
class FacilityApprovalController extends Controller
{
    public function index(): View
    {
        $facilities = Facility::query()
            ->where('status', FacilityStatus::PendingReview)
            ->with('admin')
            ->latest()
            ->latest('id')
            ->paginate(25);

        return view('system.facilities.pending', ['facilities' => $facilities]);
    }

    public function show(Facility $facility): View
    {
        $facility->load('reviewer');

        return view('system.facilities.show', [
            'facility' => $facility,
            'admins' => $facility->users()->where('role', UserRole::Admin)->orderBy('id')->get(),
            'invitedStaffCount' => $facility->users()->where('role', '!=', UserRole::Admin)->count(),
            'departments' => $facility->departments()->orderBy('id')->get(),
        ]);
    }

    public function approve(Request $request, Facility $facility, ReviewFacility $reviewFacility): RedirectResponse
    {
        $notified = $reviewFacility->approve($facility, $request->user());

        if ($notified === null) {
            return $this->alreadyReviewed($facility);
        }

        return redirect()
            ->route('system.facilities.pending')
            ->with('success', "{$facility->name} is now active. ".($notified
                ? 'Its admin has been emailed.'
                : "We couldn't email its admin, so let them know directly."));
    }

    public function reject(RejectFacilityRequest $request, Facility $facility, ReviewFacility $reviewFacility): RedirectResponse
    {
        $notified = $reviewFacility->reject($facility, $request->user(), $request->validated('reason'));

        if ($notified === null) {
            return $this->alreadyReviewed($facility);
        }

        return redirect()
            ->route('system.facilities.pending')
            ->with('success', "{$facility->name} was rejected. ".($notified
                ? 'Its admin has been emailed the reason.'
                : "We couldn't email its admin, so let them know directly."));
    }

    private function alreadyReviewed(Facility $facility): RedirectResponse
    {
        return redirect()
            ->route('system.facilities.show', $facility)
            ->withErrors(['review' => 'This registration has already been reviewed, so nothing was changed.']);
    }
}
