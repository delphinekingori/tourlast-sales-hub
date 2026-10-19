<?php

namespace App\Actions\Travel\Payments;

use App\Actions\Travel\RefreshBookingPayment;
use App\Enums\Permission;
use App\Enums\Travel\CancellationStatus;
use App\Enums\Travel\PaymentMethod;
use App\Enums\Travel\RefundStatus;
use App\Models\TravelRefund;
use App\Support\Alerts;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Applies a Daraja B2C result (or queue timeout) to the refund it belongs to.
 * Safe to run twice: a refund that is already completed never changes again,
 * and a failure never overrides a payout that did go out.
 */
class ApplyB2cResult
{
    public function __construct(private RefreshBookingPayment $refresh) {}

    /**
     * @param  array<string, mixed>  $payload  the raw Safaricom body
     * @return array{0: null, 1: ?string, 2: ?int} payment id (always null), a note when nothing was applied, refund id
     */
    public function handle(array $payload, bool $timedOut = false): array
    {
        $result = $payload['Result'] ?? null;

        if (! is_array($result) || (blank($result['OriginatorConversationID'] ?? null) && blank($result['ConversationID'] ?? null))) {
            return [null, 'Not a B2C result (no OriginatorConversationID).', null];
        }

        $refund = TravelRefund::query()
            ->when(filled($result['OriginatorConversationID'] ?? null), fn ($query) => $query->where('originator_conversation_id', (string) $result['OriginatorConversationID']))
            ->when(blank($result['OriginatorConversationID'] ?? null), fn ($query) => $query->where('conversation_id', (string) $result['ConversationID']))
            ->first();

        if (! $refund) {
            return [null, 'Unknown payout '.($result['OriginatorConversationID'] ?? $result['ConversationID']).'; ignored.', null];
        }

        $note = DB::transaction(function () use ($refund, $result, $timedOut): ?string {
            $refund = TravelRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($refund->status === RefundStatus::Completed) {
                return null;
            }

            $code = array_key_exists('ResultCode', $result) ? (int) $result['ResultCode'] : null;
            $description = (string) ($result['ResultDesc'] ?? '');

            if ($timedOut && $code === null) {
                return $this->timedOut($refund);
            }

            return $code === 0 ? $this->succeeded($refund, $result) : $this->failed($refund, $code, $description);
        });

        return [null, $note, $refund->id];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function succeeded(TravelRefund $refund, array $result): ?string
    {
        $parameters = collect($result['ResultParameters']['ResultParameter'] ?? [])
            ->filter(fn ($item) => is_array($item) && isset($item['Key']))
            ->mapWithKeys(fn (array $item) => [$item['Key'] => $item['Value'] ?? null]);

        $receipt = (string) ($parameters->get('TransactionReceipt') ?: ($result['TransactionID'] ?? ''));

        $refund->forceFill([
            'status' => RefundStatus::Completed,
            'method' => PaymentMethod::Mpesa,
            'reference' => mb_substr($receipt !== '' ? $receipt : (string) $refund->originator_conversation_id, 0, 60),
            'failure_reason' => null,
            'processed_at' => now(),
        ])->save();

        $booking = $refund->booking;

        $refund->cancellation?->forceFill(['status' => CancellationStatus::Completed, 'processed_by' => $refund->processed_by, 'processed_at' => now()])->save();
        $this->refresh->handle($booking);

        Audit::record($booking, 'refund.completed', 'Refund of KES '.number_format((float) $refund->amount, 2).' on '.$booking->reference.' paid by M-Pesa ('.$refund->reference.')');
        Alerts::sendToTravelPermission(Permission::ManageTravelPayments, 'travel_refund', 'Refund paid', 'The KES '.number_format((float) $refund->amount, 2)." refund on {$booking->reference} was paid by M-Pesa ({$refund->reference}).", route('travel.cancellations.index', ['tab' => 'refunds']));
        Alerts::sendTravel('travel_refund', 'Refund paid', 'The KES '.number_format((float) $refund->amount, 2)." refund on {$booking->reference} was paid.", route('travel.bookings.show', $booking), $booking->salesperson);

        $paid = $parameters->get('TransactionAmount');

        return $paid !== null && abs((float) $paid - (float) $refund->amount) > 0.009
            ? 'Paid amount KES '.$paid.' differs from the refund KES '.$refund->amount.'; check with Safaricom.'
            : null;
    }

    private function failed(TravelRefund $refund, ?int $code, string $description): ?string
    {
        if ($refund->status === RefundStatus::Failed) {
            return null;
        }

        $reason = trim($description) !== '' ? trim($description) : 'M-Pesa could not complete the payout'.($code !== null ? " (code {$code})" : '').'.';

        $refund->forceFill([
            'status' => RefundStatus::Failed,
            'failure_reason' => mb_substr($reason, 0, 255),
            'processed_at' => now(),
        ])->save();

        $booking = $refund->booking;

        Audit::record($booking, 'refund.failed', 'M-Pesa refund of KES '.number_format((float) $refund->amount, 2).' on '.$booking->reference.' failed: '.$reason);
        Alerts::sendToTravelPermission(Permission::ManageTravelPayments, 'travel_refund', 'Refund failed', 'The KES '.number_format((float) $refund->amount, 2)." M-Pesa refund on {$booking->reference} failed: {$reason}", route('travel.cancellations.index', ['tab' => 'refunds']));
        Alerts::sendTravel('travel_refund', 'Refund failed', 'The KES '.number_format((float) $refund->amount, 2)." refund on {$booking->reference} failed: {$reason}", route('travel.bookings.show', $booking), $booking->salesperson);

        return null;
    }

    /**
     * Safaricom gave up queueing the request. The money may still go out, so the
     * refund stays Processing for Accounts to check.
     */
    private function timedOut(TravelRefund $refund): ?string
    {
        if ($refund->status !== RefundStatus::Processing) {
            return null;
        }

        $refund->forceFill(['failure_reason' => 'Safaricom timed out queueing this payout; the result may still arrive. Check the M-Pesa portal before retrying.'])->save();

        $booking = $refund->booking;

        Audit::record($booking, 'refund.payout_timeout', 'M-Pesa refund of KES '.number_format((float) $refund->amount, 2).' on '.$booking->reference.' timed out at Safaricom');
        Alerts::sendToTravelPermission(Permission::ManageTravelPayments, 'travel_refund', 'Refund payout timed out', "The M-Pesa refund on {$booking->reference} timed out at Safaricom. Check the M-Pesa portal, then record the reference or retry.", route('travel.cancellations.index', ['tab' => 'refunds']));

        return null;
    }
}
