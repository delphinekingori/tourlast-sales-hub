<?php

namespace App\Integrations\Flights;

use Carbon\CarbonImmutable;

/**
 * Where the Hub reads flight bookings from (config travel.flights.source).
 */
interface FlightSource
{
    /**
     * "sandbox", "api" or "push".
     */
    public function name(): string;

    /**
     * Bookings created or changed since $since (all of them when null).
     *
     * @return iterable<FlightRecord>
     */
    public function changedSince(?CarbonImmutable $since): iterable;
}
