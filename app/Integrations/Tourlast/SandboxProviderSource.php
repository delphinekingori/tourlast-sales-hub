<?php

namespace App\Integrations\Tourlast;

use App\Models\SandboxProvider;
use Carbon\CarbonImmutable;

/**
 * Reads the sample providers kept in this app. Used until the real
 * tourlast.com connection is configured.
 */
class SandboxProviderSource implements ProviderSource
{
    public function __construct(private ProviderRecordMapper $mapper) {}

    public function name(): string
    {
        return 'sandbox';
    }

    public function changedSince(?CarbonImmutable $since): iterable
    {
        $query = SandboxProvider::query()
            ->when($since, fn ($query) => $query->where('updated_at', '>', $since))
            ->orderBy('updated_at')
            ->orderBy('id');

        foreach ($query->lazy() as $provider) {
            yield $this->mapper->fromArray([
                'property_id' => $provider->property_id,
                'account_id' => $provider->account_id,
                'ref_code' => $provider->ref_code,
                'property_name' => $provider->property_name,
                'legal_name' => $provider->legal_name,
                'property_type' => $provider->property_type,
                'category' => $provider->category,
                'inventory_count' => $provider->inventory_count,
                'location' => $provider->location,
                'contact_name' => $provider->contact_name,
                'contact_phone' => $provider->contact_phone,
                'contact_email' => $provider->contact_email,
                'status' => $provider->status,
                'submitted_at' => $provider->submitted_at,
                'approved_at' => $provider->approved_at,
                'active_at' => $provider->active_at,
                'rejected_at' => $provider->rejected_at,
                'first_booking_at' => $provider->first_booking_at,
                'updated_at' => $provider->updated_at,
            ]);
        }
    }
}
