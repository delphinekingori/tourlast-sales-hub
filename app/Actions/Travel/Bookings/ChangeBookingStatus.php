<?php

namespace App\Actions\Travel\Bookings;

use App\Actions\Travel\RefreshBookingPayment;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Models\PackageDeparture;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\DepartureAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirms a pending booking, or marks a confirmed one completed or no-show
 * once the trip has started. Cancelling goes through RequestCancellation.
 */
class ChangeBookingStatus
{
    public function __construct(private RefreshBookingPayment $refresh) {}

    public function confirm(User $actor, PackageBooking $booking): PackageBooking
    {
        abort_unless($booking->isWorkableBy($actor), 403);

        DB::transaction(function () use ($booking): void {
            $departure = PackageDeparture::query()->lockForUpdate()->findOrFail($booking->package_departure_id);
            $booking->refresh();

            if ($booking->status !== TravelBookingStatus::Pending) {
                throw ValidationException::withMessages(['status' => 'Only a pending booking can be confirmed.']);
            }

            // Its own held slots are free for it to take; an expired hold must find room again.
            $available = $departure->availableSlots() + ($booking->isHoldingSlots() ? $booking->travelers : 0);

            if ($booking->travelers > $available && ! $departure->allow_overbooking) {
                throw ValidationException::withMessages(['status' => 'The hold on this booking ran out and the departure no longer has room for it.']);
            }

            $booking->forceFill(['status' => TravelBookingStatus::Confirmed, 'confirmed_at' => now(), 'hold_expires_at' => null])->save();
        });

        return $this->finish($booking, 'booking.confirmed', 'Booking '.$booking->reference.' confirmed');
    }

    public function complete(User $actor, PackageBooking $booking): PackageBooking
    {
        $this->guardAfterTrip($actor, $booking);
        $booking->forceFill(['status' => TravelBookingStatus::Completed, 'completed_at' => now()])->save();

        return $this->finish($booking, 'booking.completed', 'Booking '.$booking->reference.' completed');
    }

    public function noShow(User $actor, PackageBooking $booking): PackageBooking
    {
        $this->guardAfterTrip($actor, $booking);
        $booking->forceFill(['status' => TravelBookingStatus::NoShow])->save();

        return $this->finish($booking, 'booking.no_show', 'Booking '.$booking->reference.' marked no-show');
    }

    private function guardAfterTrip(User $actor, PackageBooking $booking): void
    {
        abort_unless($booking->isWorkableBy($actor), 403);

        if ($booking->status !== TravelBookingStatus::Confirmed) {
            throw ValidationException::withMessages(['status' => 'Only a confirmed booking can be closed off.']);
        }

        if ($booking->departure->starts_on->isFuture()) {
            throw ValidationException::withMessages(['status' => 'The trip has not started yet.']);
        }
    }

    private function finish(PackageBooking $booking, string $action, string $summary): PackageBooking
    {
        $this->refresh->handle($booking);
        Audit::record($booking, $action, $summary);
        DepartureAlerts::check($booking->departure);

        return $booking;
    }
}
