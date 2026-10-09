<?php

namespace App\Integrations\Mpesa;

/**
 * M-Pesa accepted a payment prompt. The outcome arrives later on the callback.
 */
final readonly class StkPushResult
{
    public function __construct(
        public string $merchantRequestId,
        public string $checkoutRequestId,
        public string $responseCode,
        public string $customerMessage,
    ) {}
}
