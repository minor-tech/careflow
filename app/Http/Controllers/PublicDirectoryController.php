<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Facility;
use App\Models\Service;
use App\Models\User;
use App\Services\PublicFacilityDirectory;
use App\Support\PublicFacilityCard;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The facilities anyone can look up before they have used CareFlow: a search,
 * and a page per facility. No login, and nothing here shows anything that
 * belongs to a patient, a doctor's workload, or anything clinical.
 */
class PublicDirectoryController extends Controller
{
    public function __construct(private PublicFacilityDirectory $directory) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'open_now' => ['nullable', 'boolean'],
        ]);

        $facilities = $this->directory->search($validated['q'] ?? null, $request->boolean('open_now'));

        return response()->view('directory.index', [
            'facilities' => $facilities,
            'cards' => $facilities->getCollection()->map(fn (Facility $facility): PublicFacilityCard => $this->directory->card($facility)),
            'words' => $validated['q'] ?? '',
            'openNow' => $request->boolean('open_now'),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * A facility's own public page: what it offers now, and the form to ask for a place.
     */
    public function show(string $slug): Response
    {
        // Any address at all reaches here, so a failure to look it up is "no such page", never an error page of its own.
        try {
            $facility = Facility::where('slug', $slug)->first();
        } catch (QueryException) {
            $facility = null;
        }

        abort_unless($facility?->isActive(), 404);

        $departmentIds = Department::where('facility_id', $facility->id)->where('is_active', true)->pluck('id');

        return response()->view('directory.show', [
            'facility' => $facility,
            'card' => $this->directory->card($facility),
            'availability' => $facility->remoteQueueAvailability(),
            'services' => $facility->remote_queue_allow_service_choice
                ? Service::whereIn('department_id', $departmentIds)->orderBy('name')->pluck('name', 'id')->all()
                : [],
            // Only where the facility lets patients ask for a doctor: names of doctors on duty, nothing about their workload.
            'doctors' => $facility->remote_queue_allow_doctor_choice
                ? User::where('facility_id', $facility->id)->where('role', 'doctor')->where('status', 'active')->where('is_on_duty', true)->orderBy('name')->get(['id', 'name'])
                    ->mapWithKeys(fn (User $doctor) => [$doctor->id => $doctor->doctorName()])->all()
                : [],
        ])->header('Cache-Control', 'no-store, private');
    }
}
