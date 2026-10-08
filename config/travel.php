<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tourlast Flights Super Admin
    |--------------------------------------------------------------------------
    |
    | Flights Super Admin is the source of truth for flight bookings. The Hub
    | keeps a read-only copy for sales reporting and never changes a booking.
    |
    | Sources: "sandbox" (dummy bookings, the default until the Flights team
    | connects), "api" (the Hub pulls from FLIGHTS_API_URL on a schedule) or
    | "push" (Flights Super Admin posts bookings to /api/v1/integrations/flights/bookings).
    |
    */

    'flights' => [
        'source' => env('FLIGHTS_SOURCE', 'sandbox'),
        'system' => 'tourlast-flights',
        'api_url' => env('FLIGHTS_API_URL'),
        'api_token' => env('FLIGHTS_API_TOKEN'),
        'timeout' => (int) env('FLIGHTS_API_TIMEOUT', 20),
        'admin_booking_url' => env('FLIGHTS_ADMIN_BOOKING_URL', 'https://admin.flights.tourlast.com/bookings/{id}'),
        'stale_after_minutes' => (int) env('FLIGHTS_STALE_AFTER_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | M-Pesa (Safaricom Daraja)
    |--------------------------------------------------------------------------
    |
    | Package payments go to the Hub's paybill. "sandbox" simulates Daraja so
    | everything works with dummy data; "daraja" calls Safaricom using the
    | credentials below (DARAJA_ENVIRONMENT=sandbox for Safaricom's test
    | environment, production for live).
    |
    */

    'mpesa' => [
        'driver' => env('MPESA_DRIVER', 'sandbox'),
        'environment' => env('DARAJA_ENVIRONMENT', 'sandbox'),
        'consumer_key' => env('DARAJA_CONSUMER_KEY'),
        'consumer_secret' => env('DARAJA_CONSUMER_SECRET'),
        'shortcode' => env('DARAJA_SHORTCODE', '174379'),
        'shortcode_type' => env('DARAJA_SHORTCODE_TYPE', 'paybill'),
        'till_number' => env('DARAJA_TILL_NUMBER'),
        'passkey' => env('DARAJA_PASSKEY'),
        'callback_secret' => env('DARAJA_CALLBACK_SECRET'),
        'callback_base_url' => env('DARAJA_CALLBACK_BASE_URL', env('APP_URL')),
        'allowed_ips' => array_filter(explode(',', (string) env('DARAJA_ALLOWED_IPS', ''))),
        'stk_timeout_minutes' => (int) env('DARAJA_STK_TIMEOUT_MINUTES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rules
    |--------------------------------------------------------------------------
    */

    'currency' => env('TRAVEL_CURRENCY', 'KES'),

    /** Days before a contract ends when the expiry alerts go out. */
    'contract_alert_days' => [30, 14, 7],

    /** A departure is "nearly full" at or above this share of capacity sold. */
    'nearly_full_ratio' => 0.8,

    /** Pending (unpaid) bookings hold their slots for this many hours. */
    'reservation_hold_hours' => (int) env('TRAVEL_RESERVATION_HOLD_HOURS', 48),

];
