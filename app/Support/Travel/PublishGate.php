<?php

namespace App\Support\Travel;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\ProviderContract;
use App\Models\TravelProvider;

/**
 * What must be true before a package can be marked published: an approved
 * live version, an active provider, a contract in force, prices, and
 * cancellation terms. Returns what is missing so the user can fix it.
 */
class PublishGate
{
    /**
     * Problems with the live version's commercial basis (contract rules,
     * prices, terms). A Super Admin may override these with a reason.
     *
     * @return list<string>
     */
    public static function missing(Package $package): array
    {
        $version = $package->live_version_id ? PackageVersion::query()->find($package->live_version_id) : null;

        if (! $version) {
            return ['An approved version (Sales Admin and Super Admin approval)'];
        }

        $provider = TravelProvider::query()->find($version->travel_provider_id);
        $contract = $version->provider_contract_id ? ProviderContract::query()->find($version->provider_contract_id) : null;

        if (! $provider) {
            return ['Provider'];
        }

        $missing = array_values(ContractCheck::forPackage($contract, $provider));

        if (blank($provider->phone) && blank($provider->email) && blank($provider->primary_contact_phone)) {
            $missing[] = 'Provider contact details';
        }

        if ($version->adult_price === null || (float) $version->adult_price <= 0) {
            $missing[] = 'Adult price';
        }

        if (blank($version->cancellation_policy)) {
            $missing[] = 'Cancellation policy';
        }

        return array_values(array_unique($missing));
    }

    public static function passes(Package $package): bool
    {
        return self::missing($package) === [];
    }
}
