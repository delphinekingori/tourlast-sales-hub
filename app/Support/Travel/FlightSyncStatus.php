<?php

namespace App\Support\Travel;

use App\Models\FlightSyncRun;
use Carbon\CarbonInterface;

/**
 * How fresh the Hub's copy of flight bookings is, for the line shown on
 * every flights page. Stale data is always labelled, never shown as live.
 */
class FlightSyncStatus
{
    private ?FlightSyncRun $lastSuccess;

    private ?FlightSyncRun $latest;

    public function __construct()
    {
        $this->lastSuccess = FlightSyncRun::query()->where('status', 'succeeded')->latest('finished_at')->latest('id')->first();
        $this->latest = FlightSyncRun::query()->where('status', '!=', 'running')->latest('started_at')->latest('id')->first();
    }

    public function source(): string
    {
        return (string) config('travel.flights.source', 'sandbox');
    }

    public function sourceLabel(): string
    {
        return match ($this->source()) {
            'api' => 'Flights Super Admin',
            'push' => 'Flights Super Admin (push)',
            default => 'Test data (sandbox)',
        };
    }

    public function isSandbox(): bool
    {
        return ! in_array($this->source(), ['api', 'push'], true);
    }

    public function lastSyncedAt(): ?CarbonInterface
    {
        return $this->lastSuccess?->finished_at;
    }

    /**
     * The error from the most recent run, if that run failed.
     */
    public function lastError(): ?string
    {
        return $this->latest && ! $this->latest->succeeded() ? $this->latest->error : null;
    }

    /**
     * No successful sync recently: pulls every stale_after_minutes, pushes within 24 hours.
     */
    public function isStale(): bool
    {
        $last = $this->lastSyncedAt();

        if ($last === null || $this->lastError() !== null) {
            return true;
        }

        return $this->source() === 'push'
            ? $last->lt(now()->subDay())
            : $last->lt(now()->subMinutes((int) config('travel.flights.stale_after_minutes', 30)));
    }
}
