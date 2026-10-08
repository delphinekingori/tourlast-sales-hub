<?php

namespace App\Actions\Travel\Payments;

use App\Enums\Travel\PaymentChannel;
use App\Enums\Travel\PaymentMethod;
use App\Enums\Travel\PaymentStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Integrations\Mpesa\MpesaException;
use App\Integrations\Mpesa\MpesaGateway;
use App\Integrations\Mpesa\MpesaPhone;
use App\Models\PackageBooking;
use App\Models\TravelPayment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\PaymentAccess;
use Illuminate\Validation\ValidationException;

/**
 * Sends an M-Pesa payment prompt to the client's phone for a booking and
 * records the pending payment. The result arrives on the STK callback.
 */
class RequestMpesaPayment
{
    public function __construct(private MpesaGateway $gateway) {}

    public function handle(PackageBooking $booking, string $phone, int|float|string $amount, User $by): TravelPayment
    {
        abort_unless(PaymentAccess::canCollect($by, $booking), 403);

        if (in_array($booking->status, [TravelBookingStatus::Cancelled, TravelBookingStatus::NoShow], true)) {
            throw ValidationException::withMessages(['amount' => 'This booking is cancelled; it cannot take payments.']);
        }

        $phone254 = MpesaPhone::normalise($phone);

        if ($phone254 === null) {
            throw ValidationException::withMessages(['phone' => 'Enter a Kenyan M-Pesa number such as 0712 345 678.']);
        }

        if (! is_numeric($amount) || (float) $amount != (int) $amount) {
            throw ValidationException::withMessages(['amount' => 'M-Pesa takes whole shillings only.']);
        }

        $amount = (int) $amount;
        $balance = (int) ceil($booking->balance());

        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'Enter at least KES 1.']);
        }

        if ($amount > $balance) {
            throw ValidationException::withMessages(['amount' => 'The balance on this booking is KES '.number_format($balance).'.']);
        }

        try {
            $result = $this->gateway->stkPush($phone254, $amount, $booking->reference, 'Tourlast trip');
        } catch (MpesaException $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }

        $payment = TravelPayment::query()->create([
            'package_booking_id' => $booking->id,
            'method' => PaymentMethod::Mpesa,
            'channel' => PaymentChannel::Stk,
            'status' => PaymentStatus::Pending,
            'amount' => $amount,
            'currency' => $booking->currency ?: 'KES',
            'checkout_request_id' => $result->checkoutRequestId,
            'merchant_request_id' => $result->merchantRequestId,
            'phone' => $phone254,
            'account_reference' => $booking->reference,
            'recorded_by' => $by->id,
        ]);

        Audit::record($payment, 'payment.mpesa_requested', 'M-Pesa prompt for KES '.number_format($amount).' sent for '.$booking->reference, userId: $by->id);

        return $payment;
    }
}
