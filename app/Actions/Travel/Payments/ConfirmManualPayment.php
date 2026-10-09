<?php

namespace App\Actions\Travel\Payments;

use App\Enums\Travel\PaymentChannel;
use App\Enums\Travel\PaymentStatus;
use App\Models\TravelPayment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\PaymentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounts confirms (counts) or rejects a logged cash/bank/card payment.
 * M-Pesa payments never pass through here: they cannot be changed by anyone.
 */
class ConfirmManualPayment
{
    public function __construct(private SettlePayment $settle) {}

    public function confirm(TravelPayment $payment, User $by): TravelPayment
    {
        $payment = $this->lockAwaiting($payment, $by);

        $payment->forceFill(['confirmed_by' => $by->id, 'confirmed_at' => now()])->save();
        Audit::record($payment, 'payment.manual_confirmed', 'Confirmed '.$payment->method->label().' payment of KES '.number_format((float) $payment->amount).' for '.$payment->account_reference, userId: $by->id);

        $this->settle->handle($payment);

        return $payment;
    }

    public function reject(TravelPayment $payment, string $reason, User $by): TravelPayment
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['rejectReason' => 'Say why this payment is rejected.']);
        }

        $payment = $this->lockAwaiting($payment, $by);

        $payment->forceFill([
            'status' => PaymentStatus::Cancelled,
            'result_description' => mb_substr('Rejected by Accounts: '.$reason, 0, 255),
        ])->save();
        Audit::record($payment, 'payment.manual_rejected', 'Rejected '.$payment->method->label().' payment of KES '.number_format((float) $payment->amount).' for '.$payment->account_reference.': '.$reason, userId: $by->id);

        $this->settle->handle($payment, announce: false);

        return $payment;
    }

    private function lockAwaiting(TravelPayment $payment, User $by): TravelPayment
    {
        abort_unless(PaymentAccess::confirms($by), 403);

        return DB::transaction(function () use ($payment): TravelPayment {
            $payment = TravelPayment::query()->lockForUpdate()->findOrFail($payment->id);

            abort_unless($payment->channel === PaymentChannel::Manual, 403, 'M-Pesa payments cannot be changed.');

            if ($payment->status !== PaymentStatus::Completed || $payment->confirmed_at !== null) {
                throw ValidationException::withMessages(['payment' => 'This payment has already been dealt with.']);
            }

            return $payment;
        });
    }
}
