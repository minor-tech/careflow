<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A waiting room full of phones shares one address, so this can't be per IP alone:
        // it is per patient's link, generous enough for a page polling every few seconds
        // (and a couple of open tabs), and tight enough that guessing links gets nowhere.
        RateLimiter::for('tracking', fn (Request $request) => Limit::perMinute(30)->by($request->ip().'|'.$request->route('token')));

        // A requester's status page is polled like a tracking page, and guessing codes must get nowhere.
        RateLimiter::for('remote-status', fn (Request $request) => Limit::perMinute(30)->by($request->ip().'|'.$request->route('code')));

        // Public forms that put a name on staff's screen. Per address and facility, generous enough for a waiting room
        // sharing one wifi, tight enough that a script can't flood the review list (the limits on pending requests are the second guard).
        // (This runs before the address is turned into a facility, so the route holds the plain slug.)
        RateLimiter::for('remote-request', function (Request $request) {
            $facility = $request->route('facility');

            return Limit::perMinute(20)->by($request->ip().'|'.(is_object($facility) ? $facility->getRouteKey() : $facility));
        });
    }
}
