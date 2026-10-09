<?php

namespace App\Console\Commands;

use App\Actions\Travel\Flights\SyncFlights as SyncFlightsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('travel:sync-flights {--full : Re-read every booking instead of only recent changes}')]
#[Description('Pull flight bookings from Tourlast Flights Super Admin into the Hub (read-only copy)')]
class SyncFlights extends Command
{
    public function handle(SyncFlightsAction $sync): int
    {
        if (config('travel.flights.source') === 'push') {
            $this->components->info('FLIGHTS_SOURCE=push: Flights Super Admin sends bookings to the API, nothing to pull.');

            return self::SUCCESS;
        }

        $run = $sync->handle($this->option('full') ? 'full' : 'incremental');

        if (! $run->succeeded()) {
            $this->components->error("Flights sync ({$run->source}) failed: {$run->error}");

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Flights synced from %s: %d seen, %d new, %d updated%s.',
            $run->source,
            $run->records_seen,
            $run->records_created,
            $run->records_updated,
            $run->records_failed > 0 ? ", {$run->records_failed} could not be read" : '',
        ));

        return self::SUCCESS;
    }
}
