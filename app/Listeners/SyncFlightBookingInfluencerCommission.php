<?php

namespace App\Listeners;

use App\Actions\Travel\Influencers\SyncInfluencerCommission;
use App\Events\FlightBookingSynced;

/**
 * Keeps the influencer's commission in step with a synced flight booking.
 */
class SyncFlightBookingInfluencerCommission
{
    public function __construct(private SyncInfluencerCommission $sync) {}

    public function handle(FlightBookingSynced $event): void
    {
        $this->sync->handle($event->booking);
    }
}
