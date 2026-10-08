<?php

namespace App\Actions\Travel\Bookings;

use App\Actions\Travel\RefreshBookingPayment;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Travel\CancellationStatus;
use App\Enums\Travel\RefundStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageCancellation;
use App\Models\TravelRefund;
use App\Models\User;
use App\Support\Alerts;
use App\Support\Audit;
use App\Support\Travel\DepartureAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A Sales Admin approves or rejects a cancellation. Approving cancels the
 * booking (freeing its slots) and, when a refund is due, creates an approved
 * refund for Accounts to pay out. Nobody but a Super Admin decides their own
 * request.
 */
class DecideCancellation
{
    public function __construct(private RefreshBookingPayment $refresh) {}

    public function handle(User $actor, PackageCancellation $cancellation, bool $approve, ?string $note = null): PackageCancellation
    {
        abort_unless($actor->can(Permission::ApproveTravelRefunds->value), 403);
        abort_if($cancellation->requested_by === $actor->id && ! $actor->hasRole(Role::SuperAdmin->value), 403, 'Someone else must decide your own request.');

        if ($cancellation->status !== CancellationStatus::Pending) {
            throw ValidationException::withMessages(['note' => 'This request has already been decided.']);
        }

        if (! $approve && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Say why the cancellation is rejected.']);
        }

        $booking = $cancellation->booking;

        DB::transaction(function () use ($actor, $cancellation, $approve, $note, $booking): void {
            $hasRefund = $approve && (float) $cancellation->refund_amount > 0;

            $cancellation->forceFill([
                'status' => match (true) {
                    ! $approve => CancellationStatus::Rejected,
                    $hasRefund => CancellationStatus::Approved,
                    default => CancellationStatus::Completed,
                },
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            if (! $approve) {
                return;
            }

            $booking->forceFill(['status' => TravelBookingStatus::Cancelled, 'cancelled_at' => now(), 'hold_expires_at' => null])->save();

            if ($hasRefund) {
                TravelRefund::query()->create([
                    'package_booking_id' => $booking->id,
                    'package_cancellation_id' => $cancellation->id,
                    'amount' => $cancellation->refund_amount,
                    'reason' => 'Cancellation: '.$cancellation->reason,
                    'status' => RefundStatus::Approved,
                    'requested_by' => $cancellation->requested_by,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ]);
            }
        });

        $label = $approve ? 'approved' : 'rejected';
        Audit::record($booking, 'booking.cancellation_'.$label, 'Cancellation of '.$booking->reference.' '.$label.($note ? ': '.$note : ''));

        if ($approve) {
            $this->refresh->handle($booking->refresh());
            DepartureAlerts::check($booking->departure);

            if ((float) $cancellation->refund_amount > 0) {
                Alerts::sendToTravelPermission(Permission::ManageTravelPayments, 'travel_refund', 'Refund to pay out', 'KES '.number_format((float) $cancellation->refund_amount, 2)." refund approved for {$booking->reference}.", route('travel.cancellations.index', ['tab' => 'refunds']));
            }
        }

        Alerts::sendTravel(
            'travel_booking_cancelled',
            $approve ? 'Booking cancelled' : 'Cancellation rejected',
            "The cancellation of {$booking->reference} was {$label} by {$actor->name}.",
            route('travel.bookings.show', $booking),
            $booking->salesperson,
        );

        return $cancellation;
    }
}
