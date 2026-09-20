<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DutyController extends Controller
{
    /**
     * Switch a doctor on or off duty. Patients can only be assigned to doctors
     * who are on duty, and it is an explicit switch rather than something
     * worked out from being signed in: the doctor flips it themself at the
     * start and end of their shift, or an admin does it for them.
     *
     * Going off duty doesn't move anyone: patients already in that doctor's
     * line stay there until they are seen or reassigned.
     */
    public function update(Request $request, User $staff): RedirectResponse
    {
        Gate::authorize('setDuty', $staff);

        $validated = $request->validate(['on_duty' => ['required', 'boolean']]);

        $staff->update(['is_on_duty' => $validated['on_duty']]);

        $who = $request->user()->is($staff) ? 'You are' : "{$staff->doctorName()} is";

        return back()->with('success', $who.($staff->is_on_duty ? ' now on duty.' : ' now off duty.'));
    }
}
