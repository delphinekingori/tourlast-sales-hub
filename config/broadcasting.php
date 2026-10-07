<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default broadcaster
    |--------------------------------------------------------------------------
    |
    | Set BROADCAST_CONNECTION=reverb to push alerts to the browser in real
    | time. With "log" or "null" nothing is pushed and the bell falls back to
    | polling.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast connections
    |--------------------------------------------------------------------------
    |
    | The Reverb server runs on another site. This app only sends events to it
    | (REVERB_HOST/PORT/SCHEME) using the shared app id, key and secret, and the
    | browser connects to it with the same key (see the VITE_REVERB_* values).
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                'timeout' => 5,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
