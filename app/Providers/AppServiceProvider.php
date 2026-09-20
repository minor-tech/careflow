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
    }
}
