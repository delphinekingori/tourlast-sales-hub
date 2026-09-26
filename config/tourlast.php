<?php

/*
|--------------------------------------------------------------------------
| tourlast.com connection
|--------------------------------------------------------------------------
|
| The Hub reads referred providers from tourlast.com. It never writes to
| tourlast.com. Pick one source with TOURLAST_SOURCE:
|
|   sandbox   Sample data stored in this app (default until the real
|             connection is ready). Manage it under Admin → Integration.
|   database  Read-only connection to the tourlast.com database.
|   api       Read-only JSON endpoint on tourlast.com.
|
| Signed webhooks from tourlast.com (optional) are processed the same way.
| See docs/TOURLAST_INTEGRATION.md for the full developer guide.
|
*/

return [

    'source' => env('TOURLAST_SOURCE', 'sandbox'),

    /*
    | How often the incremental sync runs, in minutes. A full re-check of every
    | referred provider also runs nightly at the time below.
    */
    'sync_every_minutes' => (int) env('TOURLAST_SYNC_EVERY_MINUTES', 10),

    'full_sync_at' => env('TOURLAST_FULL_SYNC_AT', '02:00'),

    /*
    |--------------------------------------------------------------------------
    | Read-only database source
    |--------------------------------------------------------------------------
    |
    | "connection" is defined in config/database.php (TOURLAST_DB_* env vars).
    | Map the tourlast.com column names on the right-hand side. Leave a column
    | null when tourlast.com does not have it.
    |
    */
    'database' => [
        'connection' => 'tourlast',
        'table' => env('TOURLAST_DB_TABLE', 'properties'),
        'only_referred' => true,
        'columns' => [
            'property_id' => env('TOURLAST_COL_PROPERTY_ID', 'id'),
            // Host / legal business the property belongs to (Schedule 1 groups points per legal Account)
            'account_id' => env('TOURLAST_COL_ACCOUNT_ID'),
            'legal_name' => env('TOURLAST_COL_LEGAL_NAME'),
            // "stay" or "experience"; derived from the property type when not provided
            'category' => env('TOURLAST_COL_CATEGORY'),
            // Live rooms/units (stays) or bookable services (experiences)
            'inventory_count' => env('TOURLAST_COL_INVENTORY_COUNT'),
            'ref_code' => env('TOURLAST_COL_REF_CODE', 'ref_code'),
            'property_name' => env('TOURLAST_COL_NAME', 'name'),
            'property_type' => env('TOURLAST_COL_TYPE', 'property_type'),
            'location' => env('TOURLAST_COL_LOCATION', 'city'),
            'contact_name' => env('TOURLAST_COL_CONTACT_NAME', 'contact_name'),
            'contact_phone' => env('TOURLAST_COL_CONTACT_PHONE', 'contact_phone'),
            'contact_email' => env('TOURLAST_COL_CONTACT_EMAIL', 'contact_email'),
            'status' => env('TOURLAST_COL_STATUS', 'status'),
            'submitted_at' => env('TOURLAST_COL_SUBMITTED_AT', 'created_at'),
            'approved_at' => env('TOURLAST_COL_APPROVED_AT', 'approved_at'),
            'active_at' => env('TOURLAST_COL_ACTIVE_AT', 'published_at'),
            'rejected_at' => env('TOURLAST_COL_REJECTED_AT', 'rejected_at'),
            // When the property received its first booking (for the First booking alert)
            'first_booking_at' => env('TOURLAST_COL_FIRST_BOOKING_AT'),
            'updated_at' => env('TOURLAST_COL_UPDATED_AT', 'updated_at'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Read-only API source
    |--------------------------------------------------------------------------
    |
    | GET {base_url}{path}?updated_since=ISO8601&page=N with a bearer token.
    | Expected response shape is documented in docs/TOURLAST_INTEGRATION.md.
    |
    */
    'api' => [
        'base_url' => env('TOURLAST_API_URL', 'https://www.tourlast.com'),
        'path' => env('TOURLAST_API_PATH', '/api/sales-hub/referrals'),
        'token' => env('TOURLAST_API_TOKEN'),
        'timeout' => (int) env('TOURLAST_API_TIMEOUT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | POST {APP_URL}/webhooks/tourlast, signed with HMAC-SHA256 of the raw body
    | in the X-Tourlast-Signature header ("sha256=<hex>"). Disabled while the
    | secret is empty.
    |
    */
    'webhook_secret' => env('TOURLAST_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Value mapping
    |--------------------------------------------------------------------------
    |
    | tourlast.com status and type values (left) mapped to Hub values (right).
    | Hub statuses: submitted, under_review, approved, active, rejected.
    | Hub types: keys of hub.property_types. Unmapped values fall back to
    | "submitted" and "other". Matching ignores case.
    |
    */
    'status_map' => [
        'draft' => 'submitted',
        'pending' => 'submitted',
        'submitted' => 'submitted',
        'in_review' => 'under_review',
        'under_review' => 'under_review',
        'review' => 'under_review',
        'approved' => 'approved',
        'verified' => 'approved',
        'active' => 'active',
        'live' => 'active',
        'published' => 'active',
        'rejected' => 'rejected',
        'declined' => 'rejected',
        'suspended' => 'rejected',
        'deleted' => 'rejected',
    ],

    'type_map' => [
        'hotel' => 'hotel',
        'apartment' => 'apartment',
        'villa' => 'villa',
        'guesthouse' => 'guesthouse',
        'guest_house' => 'guesthouse',
        'resort' => 'resort',
        'cabin' => 'cabin',
        'beachfront' => 'beachfront',
        'beachfront_stay' => 'beachfront',
        'cottage' => 'cottage',
        'camper' => 'camper',
        'camper_van' => 'camper',
        'rv' => 'camper',
        'tour' => 'tour',
        'safari' => 'tour',
        'experience' => 'experience',
        'activity' => 'activity',
        'restaurant' => 'restaurant',
    ],

];
