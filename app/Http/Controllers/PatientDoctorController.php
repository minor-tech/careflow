<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Service;
use App\Services\DoctorRecommender;
use App\Support\DoctorOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientDoctorController extends Controller
{
    /**
     * What the registration form shows once a department is chosen: whether it
     * gives each patient their own doctor and, if so, its services and the
     * doctors who could take this patient, ranked. Recommends and nothing more:
     * the receptionist still picks. Only ever looks within the signed-in staff
     * member's own facility.
     */
    public function __invoke(Request $request, DoctorRecommender $recommender): JsonResponse
    {
        $facility = $this->facility($request);

        $validated = $request->validate([
            'department_id' => [
                'required',
                Rule::exists('departments', 'id')->where('facility_id', $facility->id)->where('is_active', true),
            ],
            'service_id' => ['nullable', 'integer'],
        ]);

        $department = Department::findOrFail($validated['department_id']);

        if (! $department->requires_doctor_assignment) {
            return response()->json(['requires_doctor' => false, 'services' => [], 'doctors' => []]);
        }

        $services = $department->services()->orderBy('name')->get(['id', 'name']);
        $serviceId = $services->contains('id', (int) ($validated['service_id'] ?? 0)) ? (int) $validated['service_id'] : null;

        return response()->json([
            'requires_doctor' => true,
            'services' => $services->map(fn (Service $service) => ['id' => $service->id, 'name' => $service->name])->all(),
            'doctors' => array_map(
                fn (DoctorOption $option) => $option->toArray(),
                $recommender->rank($department->id, $serviceId, $facility->id),
            ),
        ]);
    }
}
