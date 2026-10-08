<?php

namespace App\Listeners;

use App\Actions\Travel\Influencers\SyncInfluencerCommission;
use App\Events\PackageBookingChanged;

/**
 * Keeps the influencer's commission in step with a package booking.
 */
class SyncPackageBookingInfluencerCommission
{
    public function __construct(private SyncInfluencerCommission $sync) {}

    public function handle(PackageBookingChanged $event): void
    {
        $this->sync->handle($event->booking);
    }
}
