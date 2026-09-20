<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may manage a staff account. Admins manage the non-admin staff of their
 * own facility only; another facility's staff simply do not exist for them.
 */
class UserPolicy
{
    public function update(User $actor, User $staff): Response
    {
        if (! $actor->isAdmin() || $actor->facility_id === null || ! $staff->belongsToFacility($actor->facility_id)) {
            return Response::denyAsNotFound();
        }

        return $staff->isAdmin()
            ? Response::deny('Admin accounts cannot be changed here.')
            : Response::allow();
    }

    /**
     * Switch a doctor on or off duty: the doctor themself, or an admin of their
     * facility. Another facility's doctors don't exist for anyone else.
     */
    public function setDuty(User $actor, User $doctor): Response
    {
        if ($actor->facility_id === null || ! $doctor->belongsToFacility($actor->facility_id) || ! $doctor->isDoctor()) {
            return Response::denyAsNotFound();
        }

        return $actor->isAdmin() || $actor->is($doctor)
            ? Response::allow()
            : Response::deny('Only an admin or the doctor themself can change whether a doctor is on duty.');
    }

    public function delete(User $actor, User $staff): Response
    {
        return $this->update($actor, $staff);
    }
}
