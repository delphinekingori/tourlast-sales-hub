<?php

namespace App\Actions\Travel\Payments;

use App\Enums\Travel\PaymentChannel;
use App\Enums\Travel\PaymentMethod;
use App\Enums\Travel\PaymentStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Models\TravelPayment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\PaymentAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Logs a cash, bank or card payment taken outside M-Pesa. It does not count
 * towards the booking until Accounts confirms it (ConfirmManualPayment).
 */
class RecordManualPayment
{
    public const Methods = [PaymentMethod::Cash, PaymentMethod::Bank, PaymentMethod::Card];

    /**
     * @param  array{method: string, amount: mixed, reference?: ?string, paid_on: string, notes?: ?string}  $input
     */
    public function handle(PackageBooking $booking, array $input, User $by): TravelPayment
    {
        abort_unless(PaymentAccess::canCollect($by, $booking), 403);

        if (in_array($booking->status, [TravelBookingStatus::Cancelled, TravelBookingStatus::NoShow], true)) {
            throw ValidationException::withMessages(['manual.amount' => 'This booking is cancelled; it cannot take payments.']);
        }

        $data = Validator::make($input, [
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $method) => $method->value, self::Methods))],
            'amount' => ['required', 'numeric', 'min:1', 'max:'.max(1, $booking->balance())],
            'reference' => [Rule::requiredIf(($input['method'] ?? null) !== PaymentMethod::Cash->value), 'nullable', 'string', 'max:60'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.max' => 'The balance on this booking is KES '.number_format($booking->balance()).'.',
            'reference.required' => 'Enter the bank or card reference.',
        ], [
            'paid_on' => 'payment date',
        ])->validate();

        $payment = TravelPayment::query()->create([
            'package_booking_id' => $booking->id,
            'method' => PaymentMethod::from($data['method']),
            'channel' => PaymentChannel::Manual,
            'status' => PaymentStatus::Completed,
            'amount' => round((float) $data['amount'], 2),
            'currency' => $booking->currency ?: 'KES',
            'reference' => $data['reference'] ?? null,
            'account_reference' => $booking->reference,
            'paid_at' => CarbonImmutable::parse($data['paid_on']),
            'recorded_by' => $by->id,
            'notes' => $data['notes'] ?? null,
        ]);

        Audit::record($payment, 'payment.manual_logged', $payment->method->label().' payment of KES '.number_format((float) $payment->amount).' logged for '.$booking->reference.'; awaiting Accounts', userId: $by->id);

        return $payment;
    }
}
