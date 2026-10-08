<?php

namespace App\Actions\Travel\Packages;

use App\Enums\Travel\PackageStatus;
use App\Models\Package;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\TravelAccess;
use Illuminate\Validation\ValidationException;

/**
 * Takes a package off sale. It stays approved and can be published again.
 */
class UnpublishPackage
{
    public function handle(User $actor, Package $package, ?string $reason = null): Package
    {
        TravelAccess::abortUnlessCanChange($actor, $package->owner_id);

        if ($package->status !== PackageStatus::Published) {
            throw ValidationException::withMessages(['package' => 'This package is not published.']);
        }

        $package->forceFill(['status' => PackageStatus::Unpublished, 'unpublished_at' => now(), 'updated_by' => $actor->id])->save();

        $reason = trim((string) $reason);
        Audit::record($package, 'package.unpublished', 'Unpublished '.$package->reference.($reason !== '' ? ': '.$reason : ''));

        return $package;
    }
}
