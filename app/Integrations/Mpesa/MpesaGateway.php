<?php

namespace App\Integrations\Mpesa;

/**
 * Talks to M-Pesa for package payments. "sandbox" (SandboxMpesaGateway)
 * simulates Safaricom with no network; "daraja" (DarajaMpesaGateway) calls the
 * real Daraja API. Chosen by config('travel.mpesa.driver').
 */
interface MpesaGateway
{
    /**
     * Sends a payment prompt (Lipa na M-Pesa Online / STK Push) to the phone.
     *
     * @param  string  $phone254  2547XXXXXXXX or 2541XXXXXXXX
     *
     * @throws MpesaException when M-Pesa refuses the request
     */
    public function stkPush(string $phone254, int $amount, string $accountReference, string $description): StkPushResult;

    /**
     * Asks M-Pesa what happened to a prompt whose callback never arrived.
     *
     * @throws MpesaException
     */
    public function stkQuery(string $checkoutRequestId): StkQueryResult;

    /**
     * Registers the paybill confirmation and validation URLs (C2B).
     *
     * @return array<string, mixed> M-Pesa's response
     *
     * @throws MpesaException
     */
    public function registerC2bUrls(): array;

    /**
     * Pays money out to a customer's phone (Business to Customer, BusinessPayment).
     * The outcome arrives later on the b2c result / timeout callbacks.
     *
     * @param  string  $phone254  2547XXXXXXXX or 2541XXXXXXXX
     * @param  string  $originatorConversationId  our unique id for this payout (idempotency key)
     *
     * @throws MpesaException when M-Pesa refuses the request
     */
    public function b2cPayout(string $phone254, int $amount, string $originatorConversationId, string $remarks): B2cPayoutResult;

    /**
     * True when no real money can move (the sandbox driver).
     */
    public function isSimulated(): bool;
}
