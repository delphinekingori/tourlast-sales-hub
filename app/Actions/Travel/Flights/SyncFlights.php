<?php

namespace App\Actions\Travel\Flights;

use App\Integrations\Flights\ApiFlightSource;
use App\Integrations\Flights\FlightSource;
use App\Models\FlightSyncRun;
use App\Providers\FlightsServiceProvider;
use App\Support\Alerts;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Pulls bookings from the configured flights source into the read-only copy.
 * A failed run leaves every existing booking untouched; nothing is ever
 * deleted. One bad booking is logged and counted without stopping the run.
 */
class SyncFlights
{
    public function __construct(private ApplyFlightRecord $apply) {}

    /**
     * @param  'full'|'incremental'  $mode
     */
    public function handle(string $mode = 'incremental'): FlightSyncRun
    {
        $source = $this->source();
        $since = $mode === 'full' ? null : $this->lastSuccessfulStart();

        $run = FlightSyncRun::query()->create([
            'source' => $source->name(),
            'mode' => $mode,
            'status' => 'running',
            'changed_since' => $since,
            'started_at' => now(),
        ]);

        $counts = ['seen' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0];
        $errors = [];

        try {
            foreach ($source->changedSince($since) as $record) {
                $counts['seen']++;

                try {
                    $result = $this->apply->handle($record, $source->name());

                    if ($result === ApplyFlightRecord::Created) {
                        $counts['created']++;
                    } elseif ($result === ApplyFlightRecord::Updated) {
                        $counts['updated']++;
                    }
                } catch (InvalidArgumentException|QueryException $exception) {
                    $counts['failed']++;
                    $errors[] = $record->externalId.': '.$exception->getMessage();
                    Log::warning('Flight booking could not be applied', ['external_id' => $record->externalId, 'exception' => $exception]);
                }
            }

            if ($source instanceof ApiFlightSource) {
                $counts['failed'] += count($source->skipped());
                $errors = [...$errors, ...$source->skipped()];
            }
        } catch (Throwable $exception) {
            Log::error('Flights sync failed', ['exception' => $exception]);

            $run->update([
                'status' => 'failed',
                'records_seen' => $counts['seen'],
                'records_created' => $counts['created'],
                'records_updated' => $counts['updated'],
                'records_failed' => $counts['failed'],
                'error' => mb_substr($exception->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);

            $this->alertFailure($exception->getMessage());

            return $run;
        }

        $run->update([
            'status' => 'succeeded',
            'records_seen' => $counts['seen'],
            'records_created' => $counts['created'],
            'records_updated' => $counts['updated'],
            'records_failed' => $counts['failed'],
            'error' => $errors === [] ? null : mb_substr(implode("\n", array_slice($errors, 0, 20)), 0, 1000),
            'finished_at' => now(),
        ]);

        Cache::forget('travel.flights.sync-alerted');

        return $run;
    }

    public function source(): FlightSource
    {
        if (! app()->bound(FlightSource::class)) {
            app()->register(FlightsServiceProvider::class);
        }

        return app(FlightSource::class);
    }

    /**
     * Start of the last successful pull, with a few minutes' overlap.
     */
    private function lastSuccessfulStart(): ?CarbonImmutable
    {
        $startedAt = FlightSyncRun::query()
            ->where('status', 'succeeded')
            ->where('mode', '!=', 'push')
            ->latest('started_at')
            ->value('started_at');

        return $startedAt ? CarbonImmutable::parse($startedAt)->subMinutes(5) : null;
    }

    /**
     * "Flight data delayed" to Travel managers, at most once an hour while failing.
     */
    private function alertFailure(string $error): void
    {
        if (! Cache::add('travel.flights.sync-alerted', true, now()->addHour())) {
            return;
        }

        Alerts::sendTravel(
            'flight_sync_failed',
            'Flight data delayed',
            'The Hub could not read bookings from Flights Super Admin: '.mb_substr($error, 0, 180).'. Flight pages show the last data received.',
            route('travel.flights.index'),
        );
    }
}
