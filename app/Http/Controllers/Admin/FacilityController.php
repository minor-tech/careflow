<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FacilityController extends Controller
{
    public function show(Request $request): View
    {
        $facility = $this->facility($request);

        return view('admin.facility', [
            'facility' => $facility,
            'staffCount' => $facility->users()->count(),
            'departmentCount' => $facility->departments()->count(),
            'departments' => $facility->departments()->withCount('users')->orderBy('name')->orderBy('id')->get(),
        ]);
    }
}
