<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The services a department offers ("Pediatrics" in Consultation). They are
 * managed on the department's own page, and give doctors a specialty and
 * patients something to be registered for.
 */
class ServiceController extends Controller
{
    public function store(Request $request, Department $department): RedirectResponse
    {
        Gate::authorize('update', $department);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('services', 'name')->where('department_id', $department->id),
            ],
        ], [
            'name.unique' => 'This department already has a service with this name.',
        ]);

        $department->services()->create($validated);

        return redirect()->route('departments.edit', $department)->with('success', "{$validated['name']} was added.");
    }

    /**
     * A service that patients were registered for stays, so their history keeps
     * saying what they came for. Doctors whose specialty it was are left with
     * none.
     */
    public function destroy(Service $service): RedirectResponse
    {
        $department = $service->department;

        Gate::authorize('update', $department);

        if ($service->visits()->exists()) {
            return redirect()->route('departments.edit', $department)->withErrors([
                'service' => "{$service->name} has patients registered for it, so it can't be removed.",
            ]);
        }

        $service->delete();

        return redirect()->route('departments.edit', $department)->with('success', "{$service->name} was removed.");
    }
}
