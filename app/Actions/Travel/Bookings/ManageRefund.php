<?php

namespace App\Actions\Travel\Bookings;

use App\Actions\Travel\RefreshBookingPayment;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Travel\CancellationStatus;
use App\Enums\Travel\PaymentMethod;
use App\Enums\Travel\RefundStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Models\TravelRefund;
use App\Models\User;
use App\Support\Alerts;
use App\Support\Audit;
use Illuminate\Validation\ValidationException;

/**
 * The refund lifecycle: requested by sales, approved or rejected by a Sales
 * Admin, then paid out by Accounts (Processing → Completed with the M-Pesa
 * or bank reference, or Failed with a reason). Only completed refunds reduce
 * what the booking has paid.
 */
class ManageRefund
{
    public function __construct(private RefreshBookingPayment $refresh) {}

    /**
     * A refund without cancelling the booking (e.g. a partial refund).
     */
    public function request(User $actor, PackageBooking $booking, float $amount, string $reason): TravelRefund
    {
        abort_unless($booking->isWorkableBy($actor), 403);

        if ($booking->status === TravelBookingStatus::Pending && (float) $booking->amount_paid <= 0) {
            throw ValidationException::withMessages(['refund_amount' => 'Nothing has been paid on this booking.']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['refund_reason' => 'Give the reason for the refund.']);
        }

        $open = (float) $booking->refunds()->whereIn('status', [RefundStatus::Requested, RefundStatus::Approved, RefundStatus::Processing])->sum('amount');
        $refundable = round((float) $booking->amount_paid - (float) $booking->amount_refunded - $open, 2);

        if ($amount <= 0 || $amount > $refundable) {
            throw ValidationException::withMessages(['refund_amount' => 'The refund must be more than zero and at most KES '.number_format(max(0, $refundable), 2).'.']);
        }

        $refund = $booking->refunds()->create([
            'amount' => round($amount, 2),
            'reason' => trim($reason),
            'status' => RefundStatus::Requested,
            'requested_by' => $actor->id,
        ]);

        Audit::record($booking, 'refund.requested', 'Refund of KES '.number_format($amount, 2).' requested on '.$booking->reference.': '.trim($reason));
        Alerts::sendToTravelPermission(Permission::ApproveTravelRefunds, 'travel_refund', 'Refund request', "{$actor->name} asked to refund KES ".number_format($amount, 2)." on {$booking->reference}.", route('travel.cancellations.index', ['tab' => 'refunds']), $actor);

        return $refund;
    }

    public function decide(User $actor, TravelRefund $refund, bool $approve, ?string $note = null): TravelRefund
    {
        abort_unless($actor->can(Permission::ApproveTravelRefunds->value), 403);
        abort_if($refund->requested_by === $actor->id && ! $actor->hasRole(Role::SuperAdmin->value), 403, 'Someone else must decide your own request.');

        if ($refund->status !== RefundStatus::Requested) {
            throw ValidationException::withMessages(['note' => 'This refund has already been decided.']);
        }

        if (! $approve && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Say why the refund is rejected.']);
        }

        $refund->forceFill([
            'status' => $approve ? RefundStatus::Approved : RefundStatus::Rejected,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'failure_reason' => $approve ? null : $note,
        ])->save();

        $booking = $refund->booking;
        Audit::record($booking, $approve ? 'refund.approved' : 'refund.rejected', 'Refund of KES '.number_format((float) $refund->amount, 2).' on '.$booking->reference.($approve ? ' approved' : ' rejected: '.$note));

        if ($approve) {
            Alerts::sendToTravelPermission(Permission::ManageTravelPayments, 'travel_refund', 'Refund to pay out', 'KES '.number_format((float) $refund->amount, 2)." refund approved for {$booking->reference}.", route('travel.cancellations.index', ['tab' => 'refunds']));
        }

        Alerts::sendTravel('travel_refund', $approve ? 'Refund approved' : 'Refund rejected', 'The KES '.number_format((float) $refund->amount, 2)." refund on {$booking->reference} was ".($approve ? 'approved' : 'rejected').'.', route('travel.bookings.show', $booking), $booking->salesperson);

        return $refund;
    }

    /**
     * Accounts pays out an approved refund.
     *
     * @param  'processing'|'completed'|'failed'  $outcome
     */
    public function process(User $actor, TravelRefund $refund, string $outcome, ?string $method = null, ?string $reference = null, ?string $reason = null): TravelRefund
    {
        abort_unless($actor->can(Permission::ManageTravelPayments->value), 403);

        $allowed = match ($outcome) {
            'processing' => [RefundStatus::Approved],
            'completed', 'failed' => [RefundStatus::Approved, RefundStatus::Processing],
            default => [],
        };

        if (! in_array($refund->status, $allowed, true)) {
            throw ValidationException::withMessages(['outcome' => 'This refund cannot be marked '.$outcome.' now.']);
        }

        if ($outcome === 'completed' && (PaymentMethod::tryFrom((string) $method) === null || blank($reference))) {
            throw ValidationException::withMessages(['reference' => 'Enter how the refund was paid and its M-Pesa or bank reference.']);
        }

        if ($outcome === 'failed' && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Say why the refund failed.']);
        }

        $refund->forceFill(match ($outcome) {
            'processing' => ['status' => RefundStatus::Processing, 'processed_by' => $actor->id],
            'completed' => ['status' => RefundStatus::Completed, 'method' => $method, 'reference' => trim((string) $reference), 'processed_by' => $actor->id, 'processed_at' => now()],
            'failed' => ['status' => RefundStatus::Failed, 'failure_reason' => $reason, 'processed_by' => $actor->id, 'processed_at' => now()],
        })->save();

        $booking = $refund->booking;

        if ($refund->cancellation) {
            $refund->cancellation->forceFill(match ($outcome) {
                'processing' => ['status' => CancellationStatus::Processed, 'processed_by' => $actor->id],
                'completed' => ['status' => CancellationStatus::Completed, 'processed_by' => $actor->id, 'processed_at' => now()],
                'failed' => [],
            })->save();
        }

        Audit::record($booking, 'refund.'.$outcome, 'Refund of KES '.number_format((float) $refund->amount, 2).' on '.$booking->reference.' '.$outcome.($reference ? ' ('.$reference.')' : '').($reason ? ': '.$reason : ''));

        if ($outcome === 'completed') {
            $this->refresh->handle($booking);
        }

        if ($outcome !== 'processing') {
            Alerts::sendTravel('travel_refund', $outcome === 'completed' ? 'Refund paid' : 'Refund failed', 'The KES '.number_format((float) $refund->amount, 2)." refund on {$booking->reference} ".($outcome === 'completed' ? 'was paid.' : 'failed: '.$reason), route('travel.bookings.show', $booking), $booking->salesperson);
        }

        return $refund;
    }
}
