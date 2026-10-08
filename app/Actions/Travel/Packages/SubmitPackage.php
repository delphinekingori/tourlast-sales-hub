<?php

namespace App\Actions\Travel\Packages;

use App\Enums\Permission;
use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\PackageVersionStatus;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Support\Alerts;
use App\Support\Audit;
use App\Support\Travel\PackageReadiness;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends the working version for Sales Admin review. Only allowed when every
 * readiness item passes.
 */
class SubmitPackage
{
    public function handle(User $actor, Package $package): PackageVersion
    {
        TravelAccess::abortUnlessCanChange($actor, $package->owner_id);

        $version = $package->workingVersion()->first();

        if (! $version || ! $version->isEditable() || $package->archived_at) {
            throw ValidationException::withMessages(['package' => 'There is no draft to submit.']);
        }

        $missing = PackageReadiness::missing($package, $version);

        if ($missing !== []) {
            throw ValidationException::withMessages(['package' => 'Complete the package before submitting. Missing: '.implode(', ', $missing).'.']);
        }

        DB::transaction(function () use ($actor, $package, $version): void {
            $version->forceFill([
                'status' => PackageVersionStatus::Submitted,
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
            ])->save();

            if (! $package->live_version_id) {
                $package->forceFill(['status' => PackageStatus::PendingApproval])->save();
            }

            Audit::record($version, 'package.submitted', 'Submitted '.$package->reference.' '.$version->label().' for approval');
        });

        Alerts::sendToTravelPermission(
            Permission::ApprovePackagesFirst,
            'package_submitted',
            'Package submitted for approval',
            $package->name.' ('.$version->label().') by '.$actor->name.' is waiting for Sales Admin review.',
            route('travel.approvals.index'),
            except: $actor,
        );

        return $version;
    }
}
