<?php

namespace App\Actions\Travel\Media;

use App\Enums\Travel\MediaUsagePermission;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\MediaRules;

/**
 * Grants, restricts or revokes permission to use a file (managers only).
 * Revoking keeps the file on packages that already show it but stops it
 * being chosen for any package from now on.
 */
class ChangeMediaPermission
{
    public function handle(MediaAsset $asset, MediaUsagePermission $permission, User $by, ?string $notes = null): MediaAsset
    {
        abort_unless(MediaRules::canChangePermission($by), 403);

        $before = $asset->usage_permission;
        $notes = filled($notes) ? trim((string) $notes) : $asset->usage_notes;

        if ($before === $permission && $notes === $asset->usage_notes) {
            return $asset;
        }

        $asset->forceFill(['usage_permission' => $permission, 'usage_notes' => $notes])->save();

        Audit::record(
            $asset,
            $permission === MediaUsagePermission::Revoked ? 'media.permission_revoked' : 'media.permission_changed',
            'Usage permission for "'.$asset->title.'": '.$before->label().' → '.$permission->label(),
            ['usage_permission' => [$before->value, $permission->value]],
            $by->id,
        );

        return $asset;
    }
}
