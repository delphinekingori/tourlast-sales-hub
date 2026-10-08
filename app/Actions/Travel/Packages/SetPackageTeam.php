<?php

namespace App\Actions\Travel\Packages;

use App\Models\Driver;
use App\Models\Guide;
use App\Models\Package;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\TravelAccess;
use Illuminate\Validation\ValidationException;

/**
 * The package's default driver and guide. A departure or booking can name
 * someone else (the most specific assignment wins).
 */
class SetPackageTeam
{
    public function handle(User $actor, Package $package, ?int $driverId, ?int $guideId, bool $guideRequired): Package
    {
        TravelAccess::abortUnlessCanChange($actor, $package->owner_id);

        if ($driverId && ! Driver::query()->whereKey($driverId)->exists()) {
            throw ValidationException::withMessages(['team.driver_id' => 'Choose a driver from the list.']);
        }

        if ($guideId && ! Guide::query()->whereKey($guideId)->exists()) {
            throw ValidationException::withMessages(['team.guide_id' => 'Choose a guide from the list.']);
        }

        $before = $package->only(['driver_id', 'guide_id', 'guide_required']);
        $package->forceFill(['driver_id' => $driverId, 'guide_id' => $guideId, 'guide_required' => $guideRequired])->save();
        $changes = Audit::diff($before, $package->only(['driver_id', 'guide_id', 'guide_required']));

        if ($changes !== []) {
            $package->forceFill(['updated_by' => $actor->id])->save();
            Audit::record($package, 'package.team_changed', 'Default driver/guide changed on '.$package->reference, $changes);
        }

        return $package;
    }
}
