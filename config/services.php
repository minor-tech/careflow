<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // SMS gateway. For Africa's Talking's sandbox use the username "sandbox"
    // and set the base URL to https://api.sandbox.africastalking.com.
    'africastalking' => [
        'username' => env('AFRICASTALKING_USERNAME'),
        'api_key' => env('AFRICASTALKING_API_KEY'),
        'base_url' => env('AFRICASTALKING_BASE_URL', 'https://api.africastalking.com'),
        // Used when a facility hasn't set its own sender ID. Sender IDs have to be registered with Africa's Talking.
        'default_sender_id' => env('AFRICASTALKING_DEFAULT_SENDER_ID', 'CAREFLOW'),
    ],

];
