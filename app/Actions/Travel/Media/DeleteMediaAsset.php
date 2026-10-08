<?php

namespace App\Actions\Travel\Media;

use App\Models\MediaAsset;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\MediaRules;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Permanently deletes a file that no package uses. Files on any package are
 * archived instead, so package history never loses an image.
 */
class DeleteMediaAsset
{
    public function handle(MediaAsset $asset, User $by): void
    {
        abort_unless(MediaRules::canEdit($by, $asset), 403);

        $packages = $asset->usage()['packages'];

        if ($packages > 0) {
            throw ValidationException::withMessages([
                'delete' => '"'.$asset->title.'" is used in '.$packages.' '.str('package')->plural($packages).' and cannot be deleted. Archive it instead.',
            ]);
        }

        Audit::record($asset, 'media.deleted', 'Deleted "'.$asset->title.'" ('.$asset->original_name.') from the Media Gallery', userId: $by->id);

        Storage::disk($asset->disk)->delete($asset->path);
        $asset->delete();
    }
}
