<?php

namespace App\Http\Controllers;

use App\Actions\AcceptRemoteRequest;
use App\Actions\DeclineRemoteRequest;
use App\Enums\RemoteRequestStatus;
use App\Exceptions\RemoteRequestRefused;
use App\Models\RemoteRequest;
use App\Models\User;
use App\Services\DoctorRecommender;
use App\Services\RemoteRequestRouting;
use App\Support\ClinicDay;
use App\Support\DoctorOption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Staff decide on the requests people have made to join the queue, from home or
 * from inside the building. Accepting one creates a real visit; declining one
 * creates nothing.
 */
class RemoteRequestReviewController extends Controller
{
    public function index(Request $request): View
    {
        $requests = $this->facility($request)
            ->remoteRequests()
            ->pending()
            ->with('service:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return view('remote-requests.index', ['requests' => $requests]);
    }

    /**
     * One request, with the doctors who could take it ranked exactly as at the
     * front desk (shortest line first), and the choice left to staff.
     */
    public function show(Request $request, RemoteRequest $remoteRequest, DoctorRecommender $recommender, RemoteRequestRouting $routing): View|RedirectResponse
    {
        Gate::authorize('review', $remoteRequest);

        if ($remoteRequest->status !== RemoteRequestStatus::Pending) {
            return redirect()->route('remote-requests.index')->withErrors(['review' => RemoteRequestRefused::alreadyReviewed()->getMessage()]);
        }

        $remoteRequest->load(['service:id,name', 'preferredDoctor:id,name']);
        $department = $routing->departmentFor($remoteRequest);
        $assigns = $department?->requires_doctor_assignment === true;

        $options = $assigns ? $recommender->rank($department->id, $remoteRequest->service_id, $remoteRequest->facility_id) : [];
        $now = ClinicDay::now();

        return view('remote-requests.show', [
            'remoteRequest' => $remoteRequest,
            'department' => $department,
            'assignsDoctors' => $assigns,
            'doctors' => array_map(fn (DoctorOption $option): array => [
                ...$option->toArray(),
                // When they would be called if they took this doctor's line now: "10:40–11:00".
                'callWindow' => $now->copy()->addMinutes($option->estimate->lowMinutes)->format('g:i').'–'.$now->copy()->addMinutes($option->estimate->highMinutes)->format('g:i'),
            ], $options),
            // The doctor they asked for, if they may and are on duty; otherwise the recommended one.
            'preselected' => collect($options)->first(fn (DoctorOption $option) => $option->doctor->id === $remoteRequest->preferred_doctor_id)?->doctor->id
                ?? ($options[0]->doctor->id ?? null),
        ]);
    }

    public function accept(Request $request, RemoteRequest $remoteRequest, AcceptRemoteRequest $accept): RedirectResponse
    {
        Gate::authorize('review', $remoteRequest);

        $validated = $request->validate(['doctor_id' => ['nullable', 'integer']]);

        $doctor = filled($validated['doctor_id'] ?? null)
            ? User::where('facility_id', $request->user()->facility_id)->find($validated['doctor_id'])
            : null;

        try {
            $visit = $accept->handle($remoteRequest, $doctor, $request->user());
        } catch (RemoteRequestRefused $exception) {
            return back()->withErrors(['review' => $exception->getMessage()]);
        }

        $visit->load('department:id,type');
        $with = $visit->isInDoctorQueue() ? " with {$doctor->doctorName()}" : '';

        return redirect()->route('remote-requests.index')->with('success', $remoteRequest->isSelfCheckin()
            ? "{$remoteRequest->name} is checked in and waiting{$with} as {$visit->queueLabel()}."
            : "{$remoteRequest->name} was accepted{$with} as {$visit->queueLabel()}. They have been texted, and are held a place until they arrive.");
    }

    public function decline(Request $request, RemoteRequest $remoteRequest, DeclineRemoteRequest $decline): RedirectResponse
    {
        Gate::authorize('review', $remoteRequest);

        $validated = $request->validate(['declined_reason' => ['nullable', 'string', 'max:255']]);

        try {
            $decline->handle($remoteRequest, $validated['declined_reason'] ?? null, $request->user());
        } catch (RemoteRequestRefused $exception) {
            return back()->withErrors(['review' => $exception->getMessage()]);
        }

        return redirect()->route('remote-requests.index')->with('success', "{$remoteRequest->name}'s request was declined.");
    }
}
