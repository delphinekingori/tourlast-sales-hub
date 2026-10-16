<?php

namespace App\Providers;

use App\Integrations\Flights\ApiFlightSource;
use App\Integrations\Flights\FlightSource;
use App\Integrations\Flights\PushFlightSource;
use App\Integrations\Flights\SandboxFlightSource;
use Illuminate\Support\ServiceProvider;

/**
 * Picks the flight bookings source from the shared TOURLAST_SOURCE switch
 * (sandbox | api | push); FLIGHTS_SOURCE overrides it for flights alone.
 */
class FlightsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FlightSource::class, fn ($app) => match (config('travel.flights.source')) {
            'api' => $app->make(ApiFlightSource::class),
            'push' => $app->make(PushFlightSource::class),
            default => $app->make(SandboxFlightSource::class),
        });
    }
}
