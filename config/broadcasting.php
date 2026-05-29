<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    | Supportati: reverb, pusher, ably, redis, log, null
    | In sviluppo: log (nessun server WS necessario)
    | In produzione: reverb (self-hosted) o pusher (managed)
    */

    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [

        // ── Laravel Reverb (self-hosted, raccomandato) ───────────────────────
        'reverb' => [
            'driver'  => 'reverb',
            'key'     => env('REVERB_APP_KEY'),
            'secret'  => env('REVERB_APP_SECRET'),
            'app_id'  => env('REVERB_APP_ID'),
            'options' => [
                'host'   => env('REVERB_HOST', '0.0.0.0'),
                'port'   => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [],
        ],

        // ── Pusher / Soketi (compatibile Pusher) ─────────────────────────────
        'pusher' => [
            'driver' => 'pusher',
            'key'    => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'eu'),
                'host'    => env('PUSHER_HOST', ''),
                'port'    => env('PUSHER_PORT', 443),
                'scheme'  => env('PUSHER_SCHEME', 'https'),
                'useTLS'  => true,
            ],
            'client_options' => [],
        ],

        'ably' => [
            'driver' => 'ably',
            'key'    => env('ABLY_KEY'),
        ],

        'redis' => [
            'driver'     => 'redis',
            'connection' => env('BROADCAST_REDIS_CONNECTION', 'default'),
        ],

        'log'  => ['driver' => 'log'],
        'null' => ['driver' => 'null'],
    ],

];
