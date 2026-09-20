<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Allow the request only if the signed-in user has one of the given
     * roles, e.g. `role:admin,doctor`.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user !== null && in_array($user->role->value, $roles, true), 403);

        return $next($request);
    }
}
