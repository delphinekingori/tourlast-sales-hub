<?php

namespace App\Actions\Travel;

use App\Enums\Travel\BookingPaymentStatus;
use App\Enums\Travel\RefundStatus;
use App\Events\PackageBookingChanged;
use App\Models\PackageBooking;
use App\Models\TravelPayment;
use App\Models\TravelRefund;

/**
 * Works out a booking's amount paid, amount refunded and payment status from
 * its payments and refunds, then announces the change. The only place these
 * three fields are written. Call after any payment, refund or booking status
 * change.
 */
class RefreshBookingPayment
{
    public function handle(PackageBooking $booking): PackageBooking
    {
        $paid = (float) TravelPayment::query()->where('package_booking_id', $booking->id)->counted()->sum('amount');
        $refunded = (float) TravelRefund::query()->where('package_booking_id', $booking->id)->where('status', RefundStatus::Completed)->sum('amount');
        $total = (float) $booking->amount_total;

        $status = match (true) {
            $refunded > 0 && $refunded >= $paid => BookingPaymentStatus::Refunded,
            $refunded > 0 => BookingPaymentStatus::PartiallyRefunded,
            $paid <= 0 => BookingPaymentStatus::Unpaid,
            $paid + 0.005 >= $total => BookingPaymentStatus::Paid,
            default => BookingPaymentStatus::PartiallyPaid,
        };

        $booking->forceFill([
            'amount_paid' => round($paid, 2),
            'amount_refunded' => round($refunded, 2),
            'payment_status' => $status,
        ])->save();

        PackageBookingChanged::dispatch($booking);

        return $booking;
    }
}
