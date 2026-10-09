<?php

namespace App\Support\Travel;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\ProviderContract;

/**
 * The checklist a package version must pass before it can be submitted for
 * approval. Every item is mandatory; departures are not needed to submit.
 */
class PackageReadiness
{
    /**
     * @return list<array{key: string, label: string, ok: bool}>
     */
    public static function check(Package $package, PackageVersion $version): array
    {
        $contract = $version->provider_contract_id ? ProviderContract::query()->find($version->provider_contract_id) : null;
        $itineraryDays = $version->relationLoaded('itineraryDays') ? $version->itineraryDays->count() : $version->itineraryDays()->count();
        $usableMedia = $package->exists
            ? $package->media()->whereNull('media_assets.archived_at')->where('media_assets.usage_permission', '!=', 'revoked')->count()
            : 0;

        return [
            ['key' => 'name', 'label' => 'Package name', 'ok' => filled($version->name)],
            ['key' => 'description', 'label' => 'Short and full description', 'ok' => filled($version->short_description) && filled($version->description)],
            ['key' => 'provider', 'label' => 'Provider', 'ok' => filled($version->travel_provider_id)],
            ['key' => 'contract', 'label' => 'Active provider contract', 'ok' => $contract !== null && $contract->travel_provider_id === $version->travel_provider_id && $contract->isInForce()],
            ['key' => 'destination', 'label' => 'Destination', 'ok' => filled($version->destination)],
            ['key' => 'pricing', 'label' => 'Pricing (adult price)', 'ok' => $version->adult_price !== null && (float) $version->adult_price > 0],
            ['key' => 'itinerary', 'label' => 'Itinerary (at least one day)', 'ok' => $itineraryDays > 0],
            ['key' => 'inclusions', 'label' => 'Inclusions', 'ok' => self::hasItems($version->inclusions)],
            ['key' => 'exclusions', 'label' => 'Exclusions', 'ok' => self::hasItems($version->exclusions)],
            ['key' => 'cancellation_policy', 'label' => 'Cancellation policy', 'ok' => filled($version->cancellation_policy)],
            ['key' => 'refund_policy', 'label' => 'Refund policy', 'ok' => filled($version->refund_policy)],
            ['key' => 'media', 'label' => 'Media (at least one image)', 'ok' => $usableMedia > 0],
            ['key' => 'capacity', 'label' => 'Capacity', 'ok' => (int) $version->default_capacity > 0 || (int) $version->max_travelers > 0],
        ];
    }

    public static function isReady(Package $package, PackageVersion $version): bool
    {
        return collect(self::check($package, $version))->every(fn (array $item): bool => $item['ok']);
    }

    /**
     * @return list<string>
     */
    public static function missing(Package $package, PackageVersion $version): array
    {
        return collect(self::check($package, $version))->reject(fn (array $item): bool => $item['ok'])->pluck('label')->values()->all();
    }

    /**
     * @param  array<int, string>|null  $items
     */
    private static function hasItems(?array $items): bool
    {
        return collect($items ?? [])->filter(fn ($item): bool => filled($item))->isNotEmpty();
    }
}
