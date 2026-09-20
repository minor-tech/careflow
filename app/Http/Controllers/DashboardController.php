<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Send each role to its own dashboard shell.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        return redirect()->route(match ($request->user()->role) {
            UserRole::Admin => 'admin.facility',
            UserRole::Receptionist, UserRole::Doctor, UserRole::Nurse => 'queue.index',
            UserRole::SystemAdmin => 'system.facilities.pending',
        });
    }
}
