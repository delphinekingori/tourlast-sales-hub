<?php

namespace App\Actions\Travel\Packages;

use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\PackageVersionStatus;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes a version out of review so it can be edited again. Earlier review
 * decisions stay in the approval history; the next submission starts a new
 * review round.
 */
class WithdrawPackage
{
    public function handle(User $actor, Package $package): PackageVersion
    {
        TravelAccess::abortUnlessCanChange($actor, $package->owner_id);

        $version = $package->workingVersion()->first();

        if (! $version || ! $version->isAwaitingReview()) {
            throw ValidationException::withMessages(['package' => 'Nothing is waiting for approval.']);
        }

        DB::transaction(function () use ($package, $version): void {
            $version->forceFill(['status' => PackageVersionStatus::Draft])->save();

            if (! $package->live_version_id) {
                $package->forceFill(['status' => PackageStatus::Draft])->save();
            }

            Audit::record($version, 'package.withdrawn', 'Withdrew '.$package->reference.' '.$version->label().' from review');
        });

        return $version;
    }
}
