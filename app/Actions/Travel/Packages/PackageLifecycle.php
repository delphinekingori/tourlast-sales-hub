<?php

namespace App\Actions\Travel\Packages;

use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\PackageVersionStatus;
use App\Models\Package;
use App\Models\PackageVersion;

/**
 * Promotes an approved version to be the one customers see.
 */
class PackageLifecycle
{
    public static function makeLive(Package $package, PackageVersion $version): void
    {
        if ($package->live_version_id && $package->live_version_id !== $version->id) {
            PackageVersion::query()->whereKey($package->live_version_id)->update(['status' => PackageVersionStatus::Superseded]);
        }

        $package->forceFill([
            'live_version_id' => $version->id,
            'working_version_id' => null,
            'name' => $version->name,
            'package_type' => $version->package_type,
            'destination' => $version->destination,
            'country' => $version->country,
            'travel_provider_id' => $version->travel_provider_id,
            'provider_contract_id' => $version->provider_contract_id,
            'status' => in_array($package->status, [PackageStatus::Draft, PackageStatus::PendingApproval], true) ? PackageStatus::Approved : $package->status,
        ])->save();

        $package->unsetRelation('liveVersion');
        $package->unsetRelation('workingVersion');
    }
}
