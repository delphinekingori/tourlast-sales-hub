<?php

namespace App\Actions\Travel\Media;

use App\Models\MediaAsset;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\MediaRules;
use App\Support\Travel\TravelAccess;
use Illuminate\Validation\ValidationException;

/**
 * Archives a file (hidden from the gallery and from package selection; kept
 * on packages that already show it) or restores it.
 */
class ArchiveMediaAsset
{
    public function archive(MediaAsset $asset, User $by, ?string $confirmation = null): MediaAsset
    {
        abort_unless(MediaRules::canEdit($by, $asset), 403);

        if ($asset->archived_at !== null) {
            return $asset;
        }

        $usage = $asset->usage();

        if ($usage['published'] > 0) {
            abort_unless(TravelAccess::managesAll($by), 403, 'Only a Sales Admin can archive a file used on published packages.');

            if (trim((string) $confirmation) !== MediaRules::ArchiveConfirmation) {
                throw ValidationException::withMessages([
                    'archiveConfirmation' => 'This file is on '.$usage['published'].' published '.str('package')->plural($usage['published']).'. Type "'.MediaRules::ArchiveConfirmation.'" to confirm.',
                ]);
            }
        }

        $asset->forceFill(['archived_at' => now()])->save();

        Audit::record($asset, 'media.archived', 'Archived "'.$asset->title.'"'.($usage['packages'] ? ' (used in '.$usage['packages'].' '.str('package')->plural($usage['packages']).')' : ''), userId: $by->id);

        return $asset;
    }

    public function restore(MediaAsset $asset, User $by): MediaAsset
    {
        abort_unless(MediaRules::canEdit($by, $asset), 403);

        if ($asset->archived_at === null) {
            return $asset;
        }

        $asset->forceFill(['archived_at' => null])->save();
        Audit::record($asset, 'media.restored', 'Restored "'.$asset->title.'" from the archive', userId: $by->id);

        return $asset;
    }
}
