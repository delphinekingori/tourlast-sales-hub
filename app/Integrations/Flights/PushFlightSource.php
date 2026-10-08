<?php

namespace App\Integrations\Flights;

use Carbon\CarbonImmutable;

/**
 * FLIGHTS_SOURCE=push: Flights Super Admin posts bookings to
 * POST /api/v1/integrations/flights/bookings, so there is nothing to pull.
 */
class PushFlightSource implements FlightSource
{
    public function name(): string
    {
        return 'push';
    }

    public function changedSince(?CarbonImmutable $since): iterable
    {
        return [];
    }
}
