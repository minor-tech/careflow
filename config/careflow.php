<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clinic Day Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone that decides when "a new day" starts for queue numbers, which
    | reset at midnight local time. Kept separate from the application timezone
    | so timestamps elsewhere are unaffected.
    |
    */

    'timezone' => env('CAREFLOW_TIMEZONE', 'Africa/Nairobi'),

    /*
    |--------------------------------------------------------------------------
    | Support Email
    |--------------------------------------------------------------------------
    |
    | Where messages from the public contact form are also emailed. Optional:
    | every message is saved either way, so leaving this empty loses nothing.
    |
    */

    'support_email' => env('CAREFLOW_SUPPORT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Patient Tracking Page
    |--------------------------------------------------------------------------
    |
    | poll_seconds: how often an open tracking page asks for news. Short enough
    | that the count of patients ahead moves as it happens (6, 5, 4...), long
    | enough to be cheap: each check is one small request. Keep it 5 to 10.
    |
    | default_minutes_per_patient: what the wait estimate assumes for a
    | department that hasn't finished enough visits yet to measure.
    |
    */

    'tracking' => [
        'poll_seconds' => (int) env('CAREFLOW_TRACKING_POLL_SECONDS', 7),
        'default_minutes_per_patient' => (int) env('CAREFLOW_DEFAULT_MINUTES_PER_PATIENT', 8),
    ],

];
