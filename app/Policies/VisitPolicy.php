<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Auth\Access\Response;

/**
 * Staff can only see visits at their own facility; another facility's visits
 * simply do not exist for them.
 */
class VisitPolicy
{
    public function view(User $user, Visit $visit): Response
    {
        if ($user->facility_id === null || ! $visit->belongsToFacility($user->facility_id)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    /**
     * Send a visit on to another department: the same rule as any other
     * queue action (you work your own department's queue, or you're an
     * admin), and the destination has to be one of this facility's own.
     */
    public function transfer(User $user, Visit $visit, Department $to): Response
    {
        $allowed = $this->updateQueue($user, $visit);

        if ($allowed->denied()) {
            return $allowed;
        }

        return $to->belongsToFacility($visit->facility_id) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Call, start, complete or cancel a visit. Staff work their own
     * department's queue only; an admin can work any department's.
     */
    public function updateQueue(User $user, Visit $visit): Response
    {
        if ($user->facility_id === null || ! $visit->belongsToFacility($user->facility_id)) {
            return Response::denyAsNotFound();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        return $visit->department_id !== null && $visit->department_id === $user->department_id
            ? Response::allow()
            : Response::deny('This visit belongs to another department.');
    }
}
