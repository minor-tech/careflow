<?php

namespace App\Http\Controllers;

use App\Actions\RegisterPatientVisit;
use App\Enums\DepartmentType;
use App\Enums\Gender;
use App\Http\Requests\StorePatientVisitRequest;
use App\Models\Visit;
use App\Services\TrackingQrCode;
use App\Support\AccessPin;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PatientVisitController extends Controller
{
    public function create(Request $request): View
    {
        $departments = $this->facility($request)
            ->departments()
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return view('patients.register', [
            'departments' => $departments->pluck('name', 'id')->all(),
            // The ones where each patient is assigned to a doctor: the form asks for one when it is chosen.
            'assignmentDepartments' => $departments->where('requires_doctor_assignment', true)->pluck('id')->values()->all(),
            'receptionId' => $departments->firstWhere('type', DepartmentType::Reception)?->id,
            'genders' => Gender::options(),
        ]);
    }

    /**
     * Registers the visit and hands its access PIN to the next screen, once.
     * The visit keeps only a hash, so this is the one moment the PIN exists in
     * the clear; it travels in the flashed session encrypted, as a new staff
     * member's temporary password does.
     */
    public function store(StorePatientVisitRequest $request, RegisterPatientVisit $registerPatientVisit): RedirectResponse
    {
        $accessPin = AccessPin::generate();

        $visit = $registerPatientVisit->handle($request->user(), $request->validated(), $accessPin);

        return redirect()->route('visits.confirmation', $visit)->with('access_pin', Crypt::encryptString($accessPin));
    }

    /**
     * The queue code and access PIN, shown straight after registering, with a
     * QR code and link to the patient's tracking page and a ticket to print. The
     * PIN is there only on the request right after registering: a reload shows
     * the queue code and QR but no PIN, because it is no longer stored anywhere
     * readable.
     */
    public function confirmation(Request $request, Visit $visit, TrackingQrCode $qrCode): Response
    {
        Gate::authorize('view', $visit);

        $visit->load(['patient', 'facility', 'department', 'assignedDoctor']);
        $trackingUrl = $visit->trackingUrl();

        return response()->view('visits.confirmation', [
            'visit' => $visit,
            'trackingUrl' => $trackingUrl,
            'qrCode' => $trackingUrl === null ? null : $qrCode->dataUri($trackingUrl),
            'accessPin' => $this->flashedAccessPin($request),
            'entryUrl' => $visit->facility->trackingEntryUrl(),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function flashedAccessPin(Request $request): ?string
    {
        $flashed = $request->session()->get('access_pin');

        if (! is_string($flashed)) {
            return null;
        }

        try {
            return Crypt::decryptString($flashed);
        } catch (DecryptException) {
            return null;
        }
    }
}
