<?php

namespace App\Integrations\Tourlast;

use App\Enums\OnboardingStatus;
use Carbon\CarbonImmutable;

/**
 * One provider/property as tourlast.com reports it, already translated into
 * Hub values. Every source (sandbox, database, API, webhook) produces these.
 */
final readonly class ProviderRecord
{
    /**
     * @param  ?string  $accountId  tourlast.com host / legal account the property belongs to
     * @param  ?string  $category  "stay" or "experience" when tourlast.com says so
     * @param  ?int  $inventoryCount  live rooms/units (stays) or bookable services (experiences)
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $propertyId,
        public ?string $refCode,
        public string $propertyName,
        public string $propertyType,
        public ?string $location,
        public ?string $contactName,
        public ?string $contactPhone,
        public ?string $contactEmail,
        public OnboardingStatus $status,
        public ?CarbonImmutable $submittedAt,
        public ?CarbonImmutable $approvedAt,
        public ?CarbonImmutable $activeAt,
        public ?CarbonImmutable $rejectedAt,
        public ?CarbonImmutable $updatedAt,
        public array $raw = [],
        public ?string $accountId = null,
        public ?string $legalName = null,
        public ?string $category = null,
        public ?int $inventoryCount = null,
        public ?CarbonImmutable $firstBookingAt = null,
    ) {}
}
