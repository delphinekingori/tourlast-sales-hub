<?php

namespace App\Actions\Travel\Payments;

use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Models\TravelPayment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\PaymentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounts puts an unmatched paybill payment on the booking it was meant for.
 * Allocation is final (there is no un-allocate); it is audited.
 */
class AllocatePayment
{
    public function __construct(private SettlePayment $settle) {}

    public function handle(TravelPayment $payment, PackageBooking $booking, User $by): TravelPayment
    {
        abort_unless(PaymentAccess::confirms($by), 403);

        if (in_array($booking->status, [TravelBookingStatus::Cancelled, TravelBookingStatus::NoShow], true)) {
            throw ValidationException::withMessages(['allocateBookingId' => 'That booking is cancelled. Refund the customer instead.']);
        }

        $payment = DB::transaction(function () use ($payment, $booking, $by): TravelPayment {
            $payment = TravelPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->package_booking_id !== null) {
                throw ValidationException::withMessages(['allocateBookingId' => 'This payment is already on a booking.']);
            }

            $payment->forceFill([
                'package_booking_id' => $booking->id,
                'allocated_by' => $by->id,
                'allocated_at' => now(),
            ])->save();

            return $payment;
        });

        Audit::record($payment, 'payment.allocated', 'Allocated M-Pesa '.$payment->mpesa_receipt.' (KES '.number_format((float) $payment->amount).', account "'.($payment->account_reference ?? '—').'") to '.$booking->reference, userId: $by->id);

        $this->settle->handle($payment->fresh());

        return $payment;
    }
}
