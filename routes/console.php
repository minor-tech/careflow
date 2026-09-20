<?php

use App\Jobs\RecalculateDepartmentAverages;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// After the clinic has closed (01:00 in the clinic's own timezone, not the server's), relearn how long each department takes.
Schedule::job(new RecalculateDepartmentAverages)
    ->name('recalculate-department-averages')
    ->dailyAt('01:00')
    ->timezone(config('careflow.timezone'))
    ->withoutOverlapping();
