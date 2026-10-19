<?php

namespace App\Actions\Travel\Payments;

use App\Enums\Permission;
use App\Enums\Travel\RefundStatus;
use App\Models\TravelRefund;
use App\Support\Alerts;
use App\Support\Audit;

/**
 * B2C has no status query, so a payout whose result never arrives is flagged
 * (once) to Accounts after TRAVEL_REFUND_STUCK_MINUTES: check the M-Pesa
 * portal, then record the reference by hand or retry.
 */
class FlagStuckRefundPayouts
{
    /**
     * @return int the number of payouts newly flagged
     */
    public function handle(): int
    {
        $minutes = max(1, (int) config('travel.refunds.stuck_minutes', 30));
        $flagged = 0;

        TravelRefund::query()
            ->where('status', RefundStatus::Processing)
            ->whereNotNull('originator_conversation_id')
            ->whereNull('payout_flagged_at')
            ->where('payout_requested_at', '<=', now()->subMinutes($minutes))
            ->with('booking')
            ->each(function (TravelRefund $refund) use ($minutes, &$flagged): void {
                $refund->forceFill(['payout_flagged_at' => now()])->save();
                $booking = $refund->booking;

                Audit::record($booking, 'refund.payout_stuck', 'M-Pesa refund of KES '.number_format((float) $refund->amount, 2).' on '.$booking->reference." has had no result for {$minutes} minutes");
                Alerts::sendToTravelPermission(Permission::ManageTravelPayments, 'travel_refund', 'Refund payout not confirmed', "The M-Pesa refund on {$booking->reference} has had no answer from Safaricom for {$minutes} minutes. Check the M-Pesa portal, then record the reference or retry.", route('travel.cancellations.index', ['tab' => 'refunds']));

                $flagged++;
            });

        return $flagged;
    }
}
