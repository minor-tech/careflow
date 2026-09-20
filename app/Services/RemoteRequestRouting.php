<?php

namespace App\Services;

use App\Enums\DepartmentType;
use App\Models\Department;
use App\Models\RemoteRequest;

/**
 * Decides which department a queue request is for. The service asked for says
 * so directly. Without one, it is the facility's first department that gives
 * each patient their own doctor, else its first Consultation, else Reception:
 * the places a walk-in with no particular need would be sent.
 */
class RemoteRequestRouting
{
    public function departmentFor(RemoteRequest $request): ?Department
    {
        $active = Department::query()
            ->where('facility_id', $request->facility_id)
            ->where('is_active', true)
            ->orderBy('id');

        if ($request->service_id !== null) {
            $department = (clone $active)->whereKey($request->service()->value('department_id'))->first();

            if ($department !== null) {
                return $department;
            }
        }

        return (clone $active)->where('requires_doctor_assignment', true)->first()
            ?? (clone $active)->where('type', DepartmentType::Consultation)->first()
            ?? (clone $active)->where('type', DepartmentType::Reception)->first();
    }
}
