<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FacilityStatusController extends Controller
{
    /**
     * The one page an admin can reach while their facility is pending review
     * or suspended.
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        $facility = $request->user()->facility;

        if ($facility === null || $facility->isActive()) {
            return redirect()->route('dashboard');
        }

        return view('facility.status', ['facility' => $facility]);
    }
}
