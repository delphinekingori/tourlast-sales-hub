<?php

namespace App\Providers;

use App\Integrations\Flights\ApiFlightSource;
use App\Integrations\Flights\FlightSource;
use App\Integrations\Flights\PushFlightSource;
use App\Integrations\Flights\SandboxFlightSource;
use Illuminate\Support\ServiceProvider;

/**
 * Picks the flight bookings source from FLIGHTS_SOURCE (sandbox | api | push).
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
