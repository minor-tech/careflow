<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientLookupController extends Controller
{
    /**
     * Tells the registration form whether this facility already has a patient
     * with the phone number being typed, so their saved details can be filled
     * in. Only ever looks within the signed-in staff member's own facility.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $phone = PhoneNumber::normalize((string) $request->query('phone'));

        $patient = $phone === null
            ? null
            : Patient::where('facility_id', $this->facility($request)->id)->where('phone', $phone)->first();

        if ($patient === null) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'patient' => [
                'name' => $patient->name,
                'dob' => $patient->dob?->toDateString(),
                'gender' => $patient->gender?->value,
            ],
        ]);
    }
}
