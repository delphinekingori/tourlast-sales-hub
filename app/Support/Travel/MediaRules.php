<?php

namespace App\Support\Travel;

use App\Models\MediaAsset;
use App\Models\User;

/**
 * Who may change, archive, revoke and delete Media Gallery files.
 *
 * - Every travel salesperson may browse and upload.
 * - The uploader and Travel managers may edit, archive and restore a file.
 * - Only managers may revoke (or restore) usage permission.
 * - A file shown on a published package can only be archived by a manager,
 *   after typing a confirmation; it can never be deleted.
 * - Deleting is only possible while no package uses the file.
 */
class MediaRules
{
    /** Typed by a manager to archive a file used on published packages. */
    public const ArchiveConfirmation = 'Archive anyway';

    public static function canEdit(User $user, MediaAsset $asset): bool
    {
        return TravelAccess::canChange($user, $asset->uploaded_by);
    }

    public static function canChangePermission(User $user): bool
    {
        return TravelAccess::works($user) && TravelAccess::managesAll($user);
    }

    public static function needsArchiveConfirmation(MediaAsset $asset): bool
    {
        return $asset->usage()['published'] > 0;
    }

    public static function canArchive(User $user, MediaAsset $asset): bool
    {
        if (! self::canEdit($user, $asset)) {
            return false;
        }

        return ! self::needsArchiveConfirmation($asset) || TravelAccess::managesAll($user);
    }

    public static function canDelete(User $user, MediaAsset $asset): bool
    {
        return self::canEdit($user, $asset) && $asset->usage()['packages'] === 0;
    }
}
