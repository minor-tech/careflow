<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Admins manage the departments of their own facility only.
 */
class DepartmentPolicy
{
    public function update(User $actor, Department $department): Response
    {
        if (! $actor->isAdmin() || $actor->facility_id === null || ! $department->belongsToFacility($actor->facility_id)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function delete(User $actor, Department $department): Response
    {
        return $this->update($actor, $department);
    }
}
