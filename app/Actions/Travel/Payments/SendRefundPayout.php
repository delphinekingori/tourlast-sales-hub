<?php

namespace App\Actions\Travel\Payments;

use App\Enums\Permission;
use App\Enums\Travel\CancellationStatus;
use App\Enums\Travel\PaymentMethod;
use App\Enums\Travel\RefundStatus;
use App\Integrations\Mpesa\MpesaException;
use App\Integrations\Mpesa\MpesaGateway;
use App\Integrations\Mpesa\MpesaPhone;
use App\Models\TravelRefund;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Accounts sends an approved refund to the client's phone through M-Pesa B2C.
 * The refund moves Approved/Failed → Processing under a row lock before
 * Safaricom is called, so a double click or a second user can never send it
 * twice; the Daraja result callback (ApplyB2cResult) then completes or fails
 * it. Every attempt uses a fresh OriginatorConversationID.
 */
class SendRefundPayout
{
    public function __construct(private MpesaGateway $gateway) {}

    public static function enabled(): bool
    {
        return (bool) config('travel.refunds.auto_payout', false);
    }

    public function handle(User $actor, TravelRefund $refund): TravelRefund
    {
        abort_unless($actor->can(Permission::ManageTravelPayments->value), 403);

        if (! self::enabled()) {
            throw ValidationException::withMessages(['refund' => 'Automatic M-Pesa refunds are switched off. Pay the refund outside the Hub and record the reference.']);
        }

        $originatorId = (string) Str::uuid();

        $refund = DB::transaction(function () use ($actor, $refund, $originatorId): TravelRefund {
            $refund = TravelRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $booking = $refund->booking()->lockForUpdate()->firstOrFail();

            if (! in_array($refund->status, [RefundStatus::Approved, RefundStatus::Failed], true)) {
                throw ValidationException::withMessages(['refund' => $refund->status === RefundStatus::Processing
                    ? 'This refund is already being paid out. Wait for M-Pesa to answer.'
                    : 'Only an approved refund can be sent.']);
            }

            $amount = (float) $refund->amount;

            if ($amount < 1 || abs($amount - round($amount)) > 0.001) {
                throw ValidationException::withMessages(['refund' => 'M-Pesa pays whole shillings only. Pay this refund outside the Hub and record the reference.']);
            }

            $others = (float) $booking->refunds()->whereKeyNot($refund->id)->whereIn('status', [RefundStatus::Requested, RefundStatus::Approved, RefundStatus::Processing])->sum('amount');
            $refundable = round((float) $booking->amount_paid - (float) $booking->amount_refunded - $others, 2);

            if ($amount > $refundable) {
                throw ValidationException::withMessages(['refund' => 'The refund (KES '.number_format($amount, 2).') is more than the booking can still refund (KES '.number_format(max(0, $refundable), 2).').']);
            }

            $phone = MpesaPhone::normalise($booking->client?->phone);

            if ($phone === null) {
                throw ValidationException::withMessages(['refund' => 'The client has no valid Safaricom phone number. Fix it on the client, or pay outside the Hub and record the reference.']);
            }

            $refund->forceFill([
                'status' => RefundStatus::Processing,
                'method' => PaymentMethod::Mpesa,
                'originator_conversation_id' => $originatorId,
                'conversation_id' => null,
                'payout_phone' => $phone,
                'payout_requested_at' => now(),
                'payout_flagged_at' => null,
                'failure_reason' => null,
                'processed_by' => $actor->id,
            ])->save();

            $refund->cancellation?->forceFill(['status' => CancellationStatus::Processed, 'processed_by' => $actor->id])->save();

            return $refund;
        });

        $booking = $refund->booking;

        try {
            $result = $this->gateway->b2cPayout((string) $refund->payout_phone, (int) round((float) $refund->amount), $originatorId, 'Refund '.$booking->reference);
        } catch (MpesaException $exception) {
            $refund->forceFill([
                'status' => RefundStatus::Failed,
                'failure_reason' => mb_substr($exception->getMessage(), 0, 255),
                'processed_at' => now(),
            ])->save();

            Audit::record($booking, 'refund.payout_failed', 'M-Pesa refund of KES '.number_format((float) $refund->amount, 2).' on '.$booking->reference.' was not accepted: '.$exception->getMessage());

            throw ValidationException::withMessages(['refund' => $exception->getMessage()]);
        }

        $refund->forceFill(['conversation_id' => $result->conversationId ?: null])->save();

        Audit::record($booking, 'refund.payout_sent', 'Refund of KES '.number_format((float) $refund->amount, 2).' on '.$booking->reference.' sent via M-Pesa to '.$refund->payout_phone.' ('.$originatorId.')');

        return $refund;
    }
}
