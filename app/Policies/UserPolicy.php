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

    public function delete(User $actor, User $staff): Response
    {
        return $this->update($actor, $staff);
    }
}
