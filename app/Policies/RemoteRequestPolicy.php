<?php

namespace App\Policies;

use App\Models\RemoteRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may review a queue request: the front desk and the admin of the facility
 * it was made to. Another facility's requests simply do not exist for anyone else.
 */
class RemoteRequestPolicy
{
    public function review(User $user, RemoteRequest $remoteRequest): Response
    {
        if ($user->facility_id === null || $remoteRequest->facility_id !== $user->facility_id) {
            return Response::denyAsNotFound();
        }

        return $user->isAdmin() || $user->isReceptionist()
            ? Response::allow()
            : Response::deny('Only reception or an admin can review queue requests.');
    }
}
