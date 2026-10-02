<?php

/*
|--------------------------------------------------------------------------
| tourlast.com connection
|--------------------------------------------------------------------------
|
| The Hub reads referred providers from tourlast.com. It never writes to
| tourlast.com. Pick one source with TOURLAST_SOURCE:
|
|   api       Read-only JSON endpoints, one per source app. This is the only
|             way the Hub talks to another app: there is no direct database
|             connection to anything but this app's own database.
|   push      Source apps push provider records into this app over the API.
|   sandbox   Sample data stored in this app (Admin -> Integration), used
|             until a source app is connected.
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
    | Database source column map (not wired up)
    |--------------------------------------------------------------------------
    |
    | NOT WIRED UP. config/database.php no longer defines a "tourlast"
    | connection and TOURLAST_SOURCE=database is rejected: the Hub reaches
    | source apps over the API only. The block below is the column map
    | DatabaseProviderSource expects, kept for that class alone.
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
            // When tourlast.com says the property stopped being live (the Inactive date)
            'inactive_at' => env('TOURLAST_COL_INACTIVE_AT'),
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
        // One or more app bases, comma separated, e.g.
        // TOURLAST_API_URL=http://tourlast-stays.test,http://experiences-v1.test
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
    | Hub statuses: submitted, under_review, approved, active, inactive, rejected.
    | Hub types: keys of hub.property_types. A status that is not listed here
    | falls back to "submitted". A type that is not listed here is stored as
    | sent, so a type added on the source side needs no change here. Matching
    | ignores case.
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
        'inactive' => 'inactive',
        'paused' => 'inactive',
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
        'lodge' => 'lodge',
        'cabin' => 'cabin',
        'chalet' => 'chalet',
        'farm_stay' => 'farm_stay',
        'treehouse' => 'treehouse',
        'boat' => 'boat',
        'houseboat' => 'boat',
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
