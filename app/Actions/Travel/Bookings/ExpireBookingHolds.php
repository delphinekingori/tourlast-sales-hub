<?php

namespace App\Actions\Travel\Bookings;

use App\Actions\Travel\RefreshBookingPayment;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Support\Audit;
use App\Support\Travel\DepartureAlerts;

/**
 * Cancels pending bookings whose slot hold has run out and nothing has been
 * paid, so their slots go back on sale. Bookings with money on them are left
 * for staff to follow up.
 */
class ExpireBookingHolds
{
    public function __construct(private RefreshBookingPayment $refresh) {}

    public function handle(): int
    {
        $expired = PackageBooking::query()
            ->where('status', TravelBookingStatus::Pending)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->where('amount_paid', '<=', 0)
            ->with('departure')
            ->get();

        foreach ($expired as $booking) {
            $booking->forceFill(['status' => TravelBookingStatus::Cancelled, 'cancelled_at' => now()])->save();
            $this->refresh->handle($booking);
            Audit::record($booking, 'booking.hold_expired', 'Hold expired: '.$booking->reference.' cancelled and its slots released');
            DepartureAlerts::check($booking->departure);
        }

        return $expired->count();
    }
}
