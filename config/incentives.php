<?php

/*
|--------------------------------------------------------------------------
| Incentives
|--------------------------------------------------------------------------
|
| Pay tables live in the incentive_policies table (seeded from Schedule 1).
| These are the operational settings around them.
|
*/

return [

    /*
    | Approval chain for each kind of claim. Steps: manager (Sales Manager or
    | Sales Admin), hr (HR) and finance (Accounts). Finance is always last and
    | is where money is released.
    */
    'approval_steps' => [
        'airtime' => ['finance'],
        'transport_reimbursement' => ['manager', 'hr', 'finance'],
        'transport_request' => ['manager', 'hr', 'finance'],
    ],

    /*
    | Optional monthly cap on transport reimbursements, in KES. Null means no cap
    | (Schedule 1 sets none); claims over the cap are flagged, not blocked.
    */
    'transport_monthly_cap' => env('HUB_TRANSPORT_MONTHLY_CAP'),

    /*
    | Receipt and ride-detail uploads.
    */
    'upload_max_kb' => 8192,

    'upload_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'heic'],

];
