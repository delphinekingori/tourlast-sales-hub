<?php

namespace App\Events;

use App\Models\PackageBooking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A package booking's payments, refunds or status changed. Dispatched by
 * RefreshBookingPayment (call it after any such change); influencer
 * commission and alerts listen for it.
 */
class PackageBookingChanged
{
    use Dispatchable;

    public function __construct(public PackageBooking $booking) {}
}
