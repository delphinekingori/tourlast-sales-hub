<?php

namespace App\Integrations\Mpesa;

/**
 * The answer to an STK status query. When $processing is true M-Pesa is still
 * waiting for the customer; otherwise $resultCode is final (0 = paid).
 */
final readonly class StkQueryResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $processing,
        public ?int $resultCode,
        public ?string $resultDescription,
        public array $raw = [],
    ) {}
}
