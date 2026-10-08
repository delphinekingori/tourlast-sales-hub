<?php

namespace App\Actions\Travel\Payments;

use App\Actions\Travel\RefreshBookingPayment;
use App\Models\TravelPayment;
use App\Support\Alerts;
use Illuminate\Support\Facades\Route;

/**
 * After a payment lands on a booking (or stops counting): refresh the
 * booking's totals and, when money arrived, tell the salesperson and the
 * Travel managers.
 */
class SettlePayment
{
    public function __construct(private RefreshBookingPayment $refresh) {}

    public function handle(TravelPayment $payment, bool $announce = true): void
    {
        $booking = $payment->booking;

        if (! $booking) {
            return;
        }

        $this->refresh->handle($booking);

        if ($announce && $payment->counts()) {
            $booking->loadMissing(['client', 'salesperson']);

            Alerts::sendTravel(
                'travel_payment_received',
                'Payment received',
                'KES '.number_format((float) $payment->amount).' from '.($booking->client?->name ?? 'the client').' for '.$booking->reference
                    .' ('.$payment->method->label().($payment->mpesa_receipt ? ' '.$payment->mpesa_receipt : '').'). Balance: KES '.number_format($booking->fresh()->balance()).'.',
                self::bookingUrl($booking->id),
                $booking->salesperson,
            );
        }
    }

    public static function bookingUrl(int $bookingId): string
    {
        return Route::has('travel.bookings.show') ? route('travel.bookings.show', $bookingId) : route('travel.payments.index');
    }
}
