<?php

namespace App\Http\Middleware;

use App\Enums\FacilityStatus;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureFacilityIsActive
{
    /**
     * Keep staff out of the working dashboards until their facility has been
     * approved (and after it is suspended). Only the admin may see the status
     * page; everyone else is signed out with an explanation. System admins
     * belong to no facility and are exempt by role. Any other account with no
     * facility is signed out rather than waved through. A suspended account is
     * signed out too, so suspending someone takes effect mid-session.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->isSuspended()) {
            return $this->signOut($request, 'Your account has been suspended. Contact your facility admin.');
        }

        if ($user->isSystemAdmin()) {
            return $next($request);
        }

        $facility = $user->facility;

        if ($facility === null) {
            return $this->signOut($request, 'Your account is not linked to a facility. Contact your facility admin.');
        }

        if ($facility->isActive()) {
            return $next($request);
        }

        if ($user->isAdmin()) {
            return redirect()->route('facility.status');
        }

        return $this->signOut($request, match ($facility->status) {
            FacilityStatus::Suspended => 'Your facility has been suspended. Contact your facility admin.',
            FacilityStatus::Rejected => 'Your facility registration was not approved. Ask your facility admin for details.',
            default => 'Your facility registration is still under review. Ask your facility admin for an update.',
        });
    }

    private function signOut(Request $request, string $message): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
