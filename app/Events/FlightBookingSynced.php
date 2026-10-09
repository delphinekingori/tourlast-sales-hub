<?php

namespace App\Events;

use App\Models\FlightBooking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A flight booking was created or updated by the flights sync.
 */
class FlightBookingSynced
{
    use Dispatchable;

    public function __construct(public FlightBooking $booking, public bool $isNew) {}
}
