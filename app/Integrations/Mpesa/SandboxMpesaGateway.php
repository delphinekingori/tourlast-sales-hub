<?php

namespace App\Integrations\Mpesa;

use Illuminate\Support\Str;

/**
 * MPESA_DRIVER=sandbox (the default): no network and no real money. Prompts
 * are accepted with made-up ids; the customer's answer is simulated from the
 * booking page or `php artisan travel:mpesa-simulate`, which post realistic
 * Daraja callbacks through the same handler Safaricom will use
 * (App\Actions\Travel\Payments\SimulateMpesaCustomer).
 */
class SandboxMpesaGateway implements MpesaGateway
{
    public function isSimulated(): bool
    {
        return true;
    }

    public function stkPush(string $phone254, int $amount, string $accountReference, string $description): StkPushResult
    {
        return new StkPushResult(
            merchantRequestId: 'SANDBOX-'.Str::upper(Str::random(8)),
            checkoutRequestId: 'ws_CO_SANDBOX_'.now()->format('dmYHis').Str::upper(Str::random(6)),
            responseCode: '0',
            customerMessage: 'Success. Request accepted for processing (test mode)',
        );
    }

    /**
     * A prompt nobody answered: the customer did not respond in time.
     */
    public function stkQuery(string $checkoutRequestId): StkQueryResult
    {
        return new StkQueryResult(
            processing: false,
            resultCode: 1037,
            resultDescription: 'DS timeout user cannot be reached (test mode)',
            raw: ['CheckoutRequestID' => $checkoutRequestId, 'ResultCode' => '1037'],
        );
    }

    public function registerC2bUrls(): array
    {
        return [
            'ResponseDescription' => 'Test mode: nothing registered with Safaricom.',
            'ConfirmationURL' => CallbackUrls::c2bConfirmation(),
            'ValidationURL' => CallbackUrls::c2bValidation(),
        ];
    }
}
