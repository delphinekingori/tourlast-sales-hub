<?php

namespace App\Actions\Travel\Packages;

use App\Models\MediaAsset;
use App\Models\Package;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\TravelAccess;
use Illuminate\Validation\ValidationException;

/**
 * Sets a package's gallery images, in order, with one primary. Media belongs
 * to the package (not a version), so changing it needs no re-approval; it
 * is audited. Only usable gallery items can be newly added.
 */
class SyncPackageMedia
{
    /**
     * @param  list<int>  $mediaIds  in display order
     */
    public function handle(User $actor, Package $package, array $mediaIds, ?int $primaryId = null): void
    {
        TravelAccess::abortUnlessCanChange($actor, $package->owner_id);

        $mediaIds = array_values(array_unique(array_map('intval', $mediaIds)));
        $current = $package->media()->pluck('media_assets.id')->all();
        $added = array_diff($mediaIds, $current);

        $usable = MediaAsset::query()->usable()->whereKey($added)->pluck('id')->all();

        if (count($usable) !== count($added)) {
            throw ValidationException::withMessages(['media' => 'Some images are archived or their permission was revoked, so they cannot be added.']);
        }

        if (MediaAsset::query()->whereKey($mediaIds)->count() !== count($mediaIds)) {
            throw ValidationException::withMessages(['media' => 'Some images no longer exist.']);
        }

        $primaryId = in_array($primaryId, $mediaIds, true) ? $primaryId : ($mediaIds[0] ?? null);
        $sync = [];

        foreach ($mediaIds as $position => $id) {
            $sync[$id] = ['position' => $position, 'is_primary' => $id === $primaryId];
        }

        $package->media()->sync($sync);

        $removed = array_diff($current, $mediaIds);

        if ($added !== [] || $removed !== [] || $current !== $mediaIds) {
            Audit::record($package, 'package.media_changed', 'Media updated on '.$package->reference.': '.count($added).' added, '.count($removed).' removed, '.count($mediaIds).' in total', [
                'media' => [$current, $mediaIds],
            ]);
        }
    }
}
