<?php

use App\Http\Controllers\Api\V1\FlightIntegrationController;
use Illuminate\Support\Facades\Route;

/*
| Tourlast Flights Super Admin -> Hub. The one shared sync token every
| integration uses (php artisan hub:generate-token; TOURLAST_API_TOKEN here,
| TOURLAST_HUB_TOKEN in the Flights app), same as the tourlast.com feed.
*/

Route::middleware(['shared-token:travel.flights.api_token', 'throttle:api'])->group(function (): void {
    Route::post('integrations/flights/bookings', [FlightIntegrationController::class, 'push'])->name('integrations.flights.push');
});
