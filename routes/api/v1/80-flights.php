<?php

use App\Http\Controllers\Api\V1\FlightIntegrationController;
use Illuminate\Support\Facades\Route;

/*
| Tourlast Flights Super Admin → Hub. Token from php artisan
| travel:create-flights-account (scope flights:push only).
*/

Route::middleware('ability:flights:push')->group(function (): void {
    Route::post('integrations/flights/bookings', [FlightIntegrationController::class, 'push'])->name('integrations.flights.push');
});
