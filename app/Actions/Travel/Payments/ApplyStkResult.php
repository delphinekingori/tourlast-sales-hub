<?php

namespace App\Actions\Travel\Payments;

use App\Enums\Travel\PaymentStatus;
use App\Models\TravelPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies M-Pesa's answer to a payment prompt, from the STK callback or a
 * status query. Only a pending payment changes, so repeated callbacks are
 * harmless.
 *
 * Result codes: 0 paid, 1032 cancelled by the customer, anything else failed
 * (1037 no answer from the phone, 1 insufficient balance, 2001 wrong PIN...).
 */
class ApplyStkResult
{
    public const CancelledByCustomer = 1032;

    public function __construct(private SettlePayment $settle) {}

    /**
     * @param  array{amount?: mixed, receipt?: ?string, date?: mixed, phone?: mixed}  $meta
     * @return string|null an error to note on the callback, or null when applied/ignored cleanly
     */
    public function handle(TravelPayment $payment, int $resultCode, ?string $description, array $meta = []): ?string
    {
        $error = null;

        $changed = DB::transaction(function () use ($payment, $resultCode, $description, $meta, &$error): bool {
            $payment = TravelPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== PaymentStatus::Pending) {
                return false;
            }

            if ($resultCode !== 0) {
                $payment->forceFill([
                    'status' => $resultCode === self::CancelledByCustomer ? PaymentStatus::Cancelled : PaymentStatus::Failed,
                    'result_code' => $resultCode,
                    'result_description' => mb_substr((string) $description, 0, 255) ?: null,
                ])->save();

                return true;
            }

            $receipt = filled($meta['receipt'] ?? null) ? strtoupper((string) $meta['receipt']) : null;

            if ($receipt && TravelPayment::query()->where('mpesa_receipt', $receipt)->whereKeyNot($payment->id)->exists()) {
                $error = 'Receipt '.$receipt.' is already recorded on another payment; ignored.';

                return false;
            }

            $requested = (float) $payment->amount;
            $actual = isset($meta['amount']) && is_numeric($meta['amount']) ? (float) $meta['amount'] : $requested;
            $notes = $payment->notes;

            if (abs($actual - $requested) > 0.004) {
                $notes = trim($notes."\n".'Requested KES '.number_format($requested, 2).', M-Pesa confirmed KES '.number_format($actual, 2).'.');
            }

            if (! $receipt) {
                $notes = trim($notes."\n".'Confirmed by M-Pesa status query; no receipt number was sent.');
            }

            $payment->forceFill([
                'status' => PaymentStatus::Completed,
                'amount' => $actual,
                'mpesa_receipt' => $receipt,
                'paid_at' => self::parseTime($meta['date'] ?? null) ?? now(),
                'phone' => filled($meta['phone'] ?? null) ? (string) $meta['phone'] : $payment->phone,
                'result_code' => 0,
                'result_description' => mb_substr((string) $description, 0, 255) ?: null,
                'notes' => $notes ?: null,
            ])->save();

            return true;
        });

        if ($changed) {
            $this->settle->handle($payment->fresh());
        }

        return $error;
    }

    /**
     * Daraja times are YmdHis in Nairobi time.
     */
    public static function parseTime(mixed $value): ?CarbonImmutable
    {
        $value = preg_replace('/\D/', '', (string) $value);

        if (strlen((string) $value) !== 14) {
            return null;
        }

        return CarbonImmutable::createFromFormat('YmdHis', $value, 'Africa/Nairobi')?->setTimezone(config('app.timezone'));
    }
}
