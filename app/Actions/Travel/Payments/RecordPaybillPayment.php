<?php

namespace App\Actions\Travel\Payments;

use App\Enums\Permission;
use App\Enums\Travel\PaymentChannel;
use App\Enums\Travel\PaymentMethod;
use App\Enums\Travel\PaymentStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Models\TravelPayment;
use App\Support\Alerts;
use Illuminate\Support\Facades\DB;

/**
 * A customer paid the paybill directly (C2B confirmation). The account number
 * they typed is matched to a booking reference; if nothing matches (or the
 * booking is cancelled) the money waits under Unmatched for Accounts.
 * The M-Pesa receipt (TransID) is unique, so a repeated confirmation is ignored.
 */
class RecordPaybillPayment
{
    public function __construct(private SettlePayment $settle) {}

    /**
     * @param  array<string, mixed>  $payload  Daraja C2B confirmation body
     */
    public function handle(array $payload): ?TravelPayment
    {
        $receipt = strtoupper(trim((string) ($payload['TransID'] ?? '')));

        if ($receipt === '' || ! is_numeric($payload['TransAmount'] ?? null)) {
            return null;
        }

        [$payment, $isNew] = DB::transaction(function () use ($payload, $receipt): array {
            if ($existing = TravelPayment::query()->where('mpesa_receipt', $receipt)->lockForUpdate()->first()) {
                return [$existing, false];
            }

            $reference = strtoupper(trim((string) ($payload['BillRefNumber'] ?? '')));
            $booking = $reference === '' ? null : PackageBooking::query()
                ->whereRaw('upper(reference) = ?', [$reference])
                ->whereNotIn('status', [TravelBookingStatus::Cancelled, TravelBookingStatus::NoShow])
                ->first();

            $payment = TravelPayment::query()->create([
                'package_booking_id' => $booking?->id,
                'method' => PaymentMethod::Mpesa,
                'channel' => PaymentChannel::Paybill,
                'status' => PaymentStatus::Completed,
                'amount' => (float) $payload['TransAmount'],
                'currency' => 'KES',
                'mpesa_receipt' => $receipt,
                'phone' => filled($payload['MSISDN'] ?? null) ? mb_substr((string) $payload['MSISDN'], 0, 20) : null,
                'payer_name' => trim(implode(' ', array_filter([$payload['FirstName'] ?? null, $payload['MiddleName'] ?? null, $payload['LastName'] ?? null]))) ?: null,
                'account_reference' => $reference !== '' ? mb_substr($reference, 0, 40) : null,
                'paid_at' => ApplyStkResult::parseTime($payload['TransTime'] ?? null) ?? now(),
                'raw' => $payload,
            ]);

            return [$payment, true];
        });

        if (! $isNew) {
            return $payment;
        }

        if ($payment->package_booking_id) {
            $this->settle->handle($payment);
        } else {
            Alerts::sendToTravelPermission(
                Permission::ManageTravelPayments,
                'payment_unmatched',
                'Unmatched M-Pesa payment',
                'KES '.number_format((float) $payment->amount).' ('.$payment->mpesa_receipt.') from '.($payment->payer_name ?? 'a customer')
                    .' with account number "'.($payment->account_reference ?? '—').'" matches no open booking. Allocate it under Payments → Unmatched.',
                route('travel.payments.index', ['tab' => 'unmatched']),
            );
        }

        return $payment;
    }
}
