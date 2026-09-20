<?php

namespace App\Http\Controllers;

use App\Actions\RegisterPatientVisit;
use App\Enums\DepartmentType;
use App\Enums\Gender;
use App\Http\Requests\StorePatientVisitRequest;
use App\Models\Visit;
use App\Services\TrackingQrCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'receptionId' => $departments->firstWhere('type', DepartmentType::Reception)?->id,
            'genders' => Gender::options(),
        ]);
    }

    public function store(StorePatientVisitRequest $request, RegisterPatientVisit $registerPatientVisit): RedirectResponse
    {
        $visit = $registerPatientVisit->handle($request->user(), $request->validated());

        return redirect()->route('visits.confirmation', $visit);
    }

    /**
     * The queue number, shown straight after registering, with a QR code and link
     * to the patient's tracking page.
     */
    public function confirmation(Visit $visit, TrackingQrCode $qrCode): View
    {
        Gate::authorize('view', $visit);

        $visit->load(['patient', 'facility']);
        $trackingUrl = $visit->trackingUrl();

        return view('visits.confirmation', [
            'visit' => $visit,
            'trackingUrl' => $trackingUrl,
            'qrCode' => $trackingUrl === null ? null : $qrCode->dataUri($trackingUrl),
        ]);
    }
}
