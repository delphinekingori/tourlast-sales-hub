<?php

namespace App\Integrations\Mpesa;

/**
 * M-Pesa accepted a payout request. The outcome arrives later on the callback.
 */
final readonly class B2cPayoutResult
{
    public function __construct(
        public string $conversationId,
        public string $originatorConversationId,
        public string $responseCode,
        public string $description,
    ) {}
}
