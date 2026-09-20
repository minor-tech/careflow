<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    /**
     * Keep an account that is still on the temporary password an admin gave it
     * on the "set your own password" screen, and nowhere else. The temporary
     * password will have been passed on by word of mouth or a message, so it
     * must not stay in use.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return redirect()->route('password.force');
        }

        return $next($request);
    }
}
