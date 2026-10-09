<?php

namespace App\Actions\Travel\Payments;

use App\Enums\Travel\PaymentChannel;
use App\Enums\Travel\PaymentStatus;
use App\Integrations\Mpesa\MpesaException;
use App\Integrations\Mpesa\MpesaGateway;
use App\Models\DarajaCallback;
use App\Models\TravelPayment;

/**
 * Catches payment prompts whose callback never arrived: asks M-Pesa for the
 * result once the prompt has timed out, and gives up after an hour.
 */
class ReconcileMpesaPayments
{
    public const GiveUpAfterMinutes = 60;

    public function __construct(private MpesaGateway $gateway, private ApplyStkResult $apply) {}

    /**
     * @return array{checked: int, completed: int, failed: int, still_pending: int}
     */
    public function handle(): array
    {
        $summary = ['checked' => 0, 'completed' => 0, 'failed' => 0, 'still_pending' => 0];
        $timeout = max(1, (int) config('travel.mpesa.stk_timeout_minutes', 3));

        TravelPayment::query()
            ->where('channel', PaymentChannel::Stk)
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '<=', now()->subMinutes($timeout))
            ->orderBy('id')
            ->each(function (TravelPayment $payment) use (&$summary): void {
                $summary['checked']++;

                try {
                    $result = $this->gateway->stkQuery((string) $payment->checkout_request_id);
                } catch (MpesaException) {
                    $result = null;
                }

                if ($result && ! $result->processing && $result->resultCode !== null) {
                    DarajaCallback::query()->create([
                        'type' => 'stk_query',
                        'payload' => $result->raw ?: ['ResultCode' => $result->resultCode, 'ResultDesc' => $result->resultDescription],
                        'travel_payment_id' => $payment->id,
                        'processed_at' => now(),
                    ]);

                    $this->apply->handle($payment, $result->resultCode, $result->resultDescription);
                    $summary[$result->resultCode === 0 ? 'completed' : 'failed']++;

                    return;
                }

                if ($payment->created_at->lte(now()->subMinutes(self::GiveUpAfterMinutes))) {
                    $this->apply->handle($payment, -1, 'No response from M-Pesa');
                    $summary['failed']++;

                    return;
                }

                $summary['still_pending']++;
            });

        return $summary;
    }
}
