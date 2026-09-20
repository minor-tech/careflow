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
     * Give a patient a new access PIN. Only reception (for their own
     * department's visits) and an admin (for any) can: a doctor or nurse has no
     * reason to be resetting a patient's access credential.
     */
    public function resetPin(User $user, Visit $visit): Response
    {
        $allowed = $this->updateQueue($user, $visit);

        if ($allowed->denied()) {
            return $allowed;
        }

        return $user->isAdmin() || $user->isReceptionist()
            ? Response::allow()
            : Response::deny('Only reception or an admin can reset a patient\'s PIN.');
    }

    /**
     * Confirm a patient accepted from home is here. The front desk does it for
     * anyone, whichever department they were accepted into, and so does an
     * admin; otherwise it is the staff who work that patient. It is not tied to
     * the patient still awaiting arrival, so that the second of two people
     * pressing Check In at once is told "already checked in" rather than that
     * they may not.
     */
    public function checkIn(User $user, Visit $visit): Response
    {
        if ($user->facility_id === null || ! $visit->belongsToFacility($user->facility_id)) {
            return Response::denyAsNotFound();
        }

        if ($user->isAdmin() || $user->isReceptionist()) {
            return Response::allow();
        }

        return $this->updateQueue($user, $visit);
    }

    /**
     * Hand a patient to a different doctor: an admin, or the doctor the
     * patient is assigned to (to pass them on before an emergency). Whether
     * the patient can still be moved is a matter of where they are in the
     * visit, which the reassignment itself checks.
     */
    public function reassignDoctor(User $user, Visit $visit): Response
    {
        if ($user->facility_id === null || ! $visit->belongsToFacility($user->facility_id)) {
            return Response::denyAsNotFound();
        }

        if ($user->isAdmin() || ($user->isDoctor() && $visit->assigned_doctor_id === $user->id)) {
            return Response::allow();
        }

        return Response::deny('Only an admin or the patient\'s own doctor can change who they are assigned to.');
    }

    /**
     * Call, start, complete or cancel a visit. Staff work their own
     * department's queue only; an admin can work any department's. Where a
     * department gives each patient their own doctor, a doctor works only
     * their own patients: the others in that department are other doctors'.
     */
    public function updateQueue(User $user, Visit $visit): Response
    {
        if ($user->facility_id === null || ! $visit->belongsToFacility($user->facility_id)) {
            return Response::denyAsNotFound();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        // The front desk checks arrivals in and cancels no-shows, whichever department the patient was accepted into.
        if ($user->isReceptionist() && $visit->isAwaitingArrival()) {
            return Response::allow();
        }

        if ($visit->department_id === null || $visit->department_id !== $user->department_id) {
            return Response::deny('This visit belongs to another department.');
        }

        if ($user->isDoctor() && $visit->department->requires_doctor_assignment && $visit->assigned_doctor_id !== $user->id) {
            return Response::deny('This patient is assigned to another doctor.');
        }

        return Response::allow();
    }
}
