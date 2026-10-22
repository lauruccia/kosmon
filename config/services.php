<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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


    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret'    => env('PAYPAL_SECRET'),
        'mode'      => env('PAYPAL_MODE', 'sandbox'), // 'sandbox' o 'live'
    ],

    'stripe' => [
        'key'             => env('STRIPE_KEY'),
        'secret'          => env('STRIPE_SECRET'),
        'webhook_secret'  => env('STRIPE_WEBHOOK_SECRET'),
        // Solo per prova con chiavi test: id di un Test clock (clock_...). Vuoto in produzione.
        'test_clock'      => env('STRIPE_TEST_CLOCK_ID'),
        // Solo prova reale: rata ogni N giorni (1-7), solo per le email elencate. Vuoto = normale.
        'fast_cycle_days'   => env('STRIPE_FAST_CYCLE_DAYS'),
        'fast_cycle_emails' => env('STRIPE_FAST_CYCLE_EMAILS'),
    ],

    'health' => [
        'token' => env('HEALTH_CHECK_TOKEN'),
    ],

];
