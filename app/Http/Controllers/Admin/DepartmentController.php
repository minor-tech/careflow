<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DepartmentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveDepartmentRequest;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(Request $request): View
    {
        $departments = $this->facility($request)
            ->departments()
            ->withCount('users')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return view('departments.index', ['departments' => $departments]);
    }

    public function create(Request $request): View
    {
        $this->facility($request);

        return view('departments.create', ['types' => DepartmentType::options()]);
    }

    public function store(SaveDepartmentRequest $request): RedirectResponse
    {
        $department = $this->facility($request)->departments()->create($request->validated());

        return redirect()->route('departments.index')->with('success', "{$department->name} was added.");
    }

    public function edit(Department $department): View
    {
        Gate::authorize('update', $department);

        return view('departments.edit', [
            'department' => $department->load(['services' => fn ($services) => $services->withCount('doctors')->orderBy('name')]),
            'types' => DepartmentType::options(),
        ]);
    }

    public function update(SaveDepartmentRequest $request, Department $department): RedirectResponse
    {
        $department->update($request->validated());

        return redirect()->route('departments.index')->with('success', "{$department->name} was updated.");
    }

    public function destroy(Department $department): RedirectResponse
    {
        Gate::authorize('delete', $department);

        if ($department->users()->exists() || $department->visits()->exists() || $department->visitEvents()->exists() || $department->services()->whereHas('visits')->exists()) {
            return redirect()->route('departments.index')->withErrors([
                'department' => "{$department->name} still has staff or visit history. Move the staff to another department, or mark this one inactive instead.",
            ]);
        }

        $department->delete();

        return redirect()->route('departments.index')->with('success', "{$department->name} was removed.");
    }
}
