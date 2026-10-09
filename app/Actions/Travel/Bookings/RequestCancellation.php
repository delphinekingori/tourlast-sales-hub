<?php

namespace App\Actions\Travel\Bookings;

use App\Enums\Permission;
use App\Enums\Travel\CancellationStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Models\PackageCancellation;
use App\Models\User;
use App\Support\Alerts;
use App\Support\Audit;
use Illuminate\Validation\ValidationException;

/**
 * Asks for a booking to be cancelled. Keeps the package's cancellation
 * policy as it stood, and a suggested refund no larger than what the client
 * has paid. A Sales Admin decides (DecideCancellation).
 */
class RequestCancellation
{
    public function handle(User $actor, PackageBooking $booking, string $reason, float $refundAmount = 0): PackageCancellation
    {
        abort_unless($booking->isWorkableBy($actor), 403);

        if (! in_array($booking->status, [TravelBookingStatus::Pending, TravelBookingStatus::Confirmed], true)) {
            throw ValidationException::withMessages(['reason' => 'Only a pending or confirmed booking can be cancelled.']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give the reason for cancelling.']);
        }

        if ($booking->cancellations()->where('status', CancellationStatus::Pending)->exists()) {
            throw ValidationException::withMessages(['reason' => 'A cancellation request for this booking is already waiting for a decision.']);
        }

        $refundable = round((float) $booking->amount_paid - (float) $booking->amount_refunded, 2);

        if ($refundAmount < 0 || $refundAmount > $refundable) {
            throw ValidationException::withMessages(['refund_amount' => 'The refund cannot be more than the KES '.number_format($refundable, 2).' the client has paid.']);
        }

        $cancellation = $booking->cancellations()->create([
            'reason' => trim($reason),
            'policy_snapshot' => $booking->version?->cancellation_policy,
            'refund_amount' => round($refundAmount, 2),
            'status' => CancellationStatus::Pending,
            'requested_by' => $actor->id,
        ]);

        Audit::record($booking, 'booking.cancellation_requested', 'Cancellation requested for '.$booking->reference.': '.trim($reason), [
            'refund_amount' => [null, round($refundAmount, 2)],
        ]);

        Alerts::sendToTravelPermission(
            Permission::ApproveTravelRefunds,
            'travel_booking_cancelled',
            'Cancellation request',
            "{$actor->name} asked to cancel {$booking->reference}".($refundAmount > 0 ? ' with a KES '.number_format($refundAmount).' refund' : '').'.',
            route('travel.cancellations.index'),
            $actor,
        );

        return $cancellation;
    }
}
