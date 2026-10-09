<?php

namespace App\Actions\Travel\Packages;

use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\TravelAccess;
use Illuminate\Validation\ValidationException;

/**
 * Retires a package. Travel managers can always archive; the owner only
 * when no upcoming bookings depend on it.
 */
class ArchivePackage
{
    public function handle(User $actor, Package $package): Package
    {
        TravelAccess::abortUnlessCanChange($actor, $package->owner_id);

        if ($package->archived_at) {
            throw ValidationException::withMessages(['package' => 'This package is already archived.']);
        }

        if (! TravelAccess::managesAll($actor) && self::upcomingBookings($package) > 0) {
            throw ValidationException::withMessages(['package' => 'This package has upcoming bookings. Ask a Sales Admin to archive it.']);
        }

        $package->forceFill(['status' => PackageStatus::Archived, 'archived_at' => now(), 'updated_by' => $actor->id])->save();
        Audit::record($package, 'package.archived', 'Archived '.$package->reference);

        return $package;
    }

    public static function upcomingBookings(Package $package): int
    {
        return PackageBooking::query()
            ->where('package_id', $package->id)
            ->whereIn('status', [TravelBookingStatus::Pending, TravelBookingStatus::Confirmed])
            ->whereHas('departure', fn ($query) => $query->whereDate('starts_on', '>=', today()))
            ->count();
    }
}
