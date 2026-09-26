<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invitations
    |--------------------------------------------------------------------------
    |
    | Accounts are invitation-only. Invitation links expire after this many days.
    |
    */

    'invitation_expiry_days' => (int) env('HUB_INVITATION_EXPIRY_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Referral codes
    |--------------------------------------------------------------------------
    |
    | Every salesperson gets a permanent code such as TL-JOHN-2847. Their
    | tracked link sends providers to the tourlast.com List Your Property page
    | with the code attached as the "ref" query parameter.
    |
    */

    'referral_prefix' => env('HUB_REFERRAL_PREFIX', 'TL'),

    'list_property_url' => env('HUB_LIST_PROPERTY_URL', 'https://www.tourlast.com/list-your-property'),

    /*
    |--------------------------------------------------------------------------
    | Property types
    |--------------------------------------------------------------------------
    |
    | Keys are stored in the database. The tourlast.com connection maps its
    | own type values onto these keys (see config/tourlast.php). Unknown types
    | are stored as "other".
    |
    */

    'property_types' => [
        'hotel' => 'Hotel',
        'resort' => 'Resort',
        'lodge' => 'Lodge',
        'apartment' => 'Apartment / Stay',
        'villa' => 'Villa',
        'guesthouse' => 'Guesthouse',
        'cabin' => 'Cabin',
        'beachfront' => 'Beachfront stay',
        'cottage' => 'Cottage',
        'camper' => 'Camper van / RV',
        'restaurant' => 'Restaurant',
        'tour' => 'Tour operator',
        'travel_agency' => 'Travel agency',
        'experience' => 'Experience provider',
        'activity' => 'Activity provider',
        'transport' => 'Transport / Transfer',
        'dmc' => 'DMC',
        'venue' => 'Event / Venue',
        'other' => 'Other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Property Engagement Registry
    |--------------------------------------------------------------------------
    |
    | Types that get accommodation fields (star rating, rooms/units) on the
    | registry form, and the job titles offered for property contacts.
    |
    */

    'accommodation_types' => ['hotel', 'resort', 'lodge', 'apartment', 'villa', 'guesthouse', 'cabin', 'beachfront', 'cottage'],

    'contact_titles' => [
        'Owner', 'General Manager', 'Managing Director', 'Revenue Manager', 'Sales Manager', 'Reservations Manager',
        'Operations Manager', 'Front Office Manager', 'Marketing Manager', 'Procurement', 'Other',
    ],

    'star_ratings' => [
        '1' => '1 Star', '2' => '2 Star', '3' => '3 Star', '4' => '4 Star', '5' => '5 Star', 'unrated' => 'Unrated',
    ],

    'default_country' => env('HUB_DEFAULT_COUNTRY', 'Kenya'),

    /*
    |--------------------------------------------------------------------------
    | Competitors
    |--------------------------------------------------------------------------
    |
    | Suggested when a property is lost to another OTA or channel. Salespeople
    | can type any other name.
    |
    */

    'competitors' => [
        'Booking.com', 'Expedia', 'Airbnb', 'Agoda', 'Trip.com', 'Hotels.com', 'TripAdvisor',
        'Jumia Travel', 'Travelstart', 'Direct bookings only', 'Tour operator / DMC contract', 'Local agent',
    ],

    /*
    |--------------------------------------------------------------------------
    | Follow-up alerts
    |--------------------------------------------------------------------------
    |
    | A signup still awaiting approval after this many days is flagged as
    | stalled. A salesperson with no logged activity for this many days is
    | flagged as inactive in the managers' daily alert.
    |
    */

    'stalled_after_days' => (int) env('HUB_STALLED_AFTER_DAYS', 14),

    'inactive_after_days' => (int) env('HUB_INACTIVE_AFTER_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Targets
    |--------------------------------------------------------------------------
    |
    | Salespeople set their own monthly target. It can be changed up to and
    | including this day of the month, then it locks.
    |
    */

    'target_lock_day' => (int) env('HUB_TARGET_LOCK_DAY', 7),

];
