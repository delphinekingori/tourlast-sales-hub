<?php

namespace Database\Seeders\Travel;

use App\Actions\Travel\Flights\SyncFlights;
use App\Integrations\Flights\FlightSource;
use App\Integrations\Flights\SandboxFlightSource;
use Illuminate\Database\Seeder;

/**
 * Demo flight bookings: one full sync from the sandbox source, so the
 * Flights pages show realistic test data until Flights Super Admin connects.
 */
class FlightsSeeder extends Seeder
{
    public function run(SyncFlights $sync): void
    {
        app()->bind(FlightSource::class, SandboxFlightSource::class);

        $run = $sync->handle('full');

        $this->command?->info("Flights: {$run->records_created} sandbox bookings synced.");
    }
}
