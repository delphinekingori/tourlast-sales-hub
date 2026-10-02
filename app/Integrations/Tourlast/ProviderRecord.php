<?php

namespace App\Integrations\Tourlast;

use App\Enums\OnboardingStatus;
use Carbon\CarbonImmutable;

/**
 * One provider/property as tourlast.com reports it, already translated into
 * Hub values. Every source (sandbox, database, API, webhook) produces these.
 * A row with isDeleted set is a tombstone: the source app no longer lists the
 * property, so the Hub archives it instead of updating it.
 */
final readonly class ProviderRecord
{
    /**
     * @param  ?string  $accountId  tourlast.com host / legal account the property belongs to
     * @param  ?string  $category  "stay" or "experience" when tourlast.com says so
     * @param  ?int  $inventoryCount  live rooms/units (stays) or bookable services (experiences)
     * @param  array<string, mixed>  $raw
     * @param  bool  $isDeleted  the source app no longer lists this property
     * @param  ?CarbonImmutable  $deletedAt  when the source app deleted it
     * @param  ?CarbonImmutable  $inactiveAt  when tourlast.com says the property stopped being live
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
        public ?CarbonImmutable $inactiveAt = null,
        public array $raw = [],
        public ?string $accountId = null,
        public ?string $legalName = null,
        public ?string $category = null,
        public ?int $inventoryCount = null,
        public ?CarbonImmutable $firstBookingAt = null,
        public bool $isDeleted = false,
        public ?CarbonImmutable $deletedAt = null,
    ) {}
}
