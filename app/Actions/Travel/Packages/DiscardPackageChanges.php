<?php

namespace App\Actions\Travel\Packages;

use App\Models\Package;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Throws away a pending change to a live package (a draft that was never
 * reviewed). Versions with review decisions are kept for the record.
 */
class DiscardPackageChanges
{
    public function handle(User $actor, Package $package): void
    {
        TravelAccess::abortUnlessCanChange($actor, $package->owner_id);

        $version = $package->workingVersion()->first();

        if (! $package->live_version_id || ! $version || ! $version->isEditable() || $version->approvals()->exists()) {
            throw ValidationException::withMessages(['package' => 'Only an unreviewed draft change to a live package can be discarded.']);
        }

        DB::transaction(function () use ($package, $version): void {
            $label = $version->label();
            $package->forceFill(['working_version_id' => null])->save();
            $version->delete();
            Audit::record($package, 'package.changes_discarded', 'Discarded draft '.$label.' of '.$package->reference);
        });
    }
}
