<?php

namespace App\Actions\Travel\Media;

use App\Models\MediaAsset;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\MediaRules;

/**
 * Edits a file's metadata. Usage permission is changed separately
 * (ChangeMediaPermission) because only managers may do it.
 */
class UpdateMediaAsset
{
    public const Fields = ['title', 'description', 'alt_text', 'travel_provider_id', 'destination', 'category', 'tags', 'source', 'copyright_owner', 'usage_notes'];

    /**
     * @param  array<string, mixed>  $data  any of Fields
     */
    public function handle(MediaAsset $asset, array $data, User $by): MediaAsset
    {
        abort_unless(MediaRules::canEdit($by, $asset), 403);

        $data = array_intersect_key($data, array_flip(self::Fields));

        if (array_key_exists('tags', $data)) {
            $data['tags'] = StoreMediaAsset::tags($data['tags']);
        }

        foreach ($data as $field => $value) {
            if (is_string($value)) {
                $data[$field] = trim($value) === '' ? null : trim($value);
            }
        }

        if (array_key_exists('travel_provider_id', $data)) {
            $data['travel_provider_id'] = $data['travel_provider_id'] ? (int) $data['travel_provider_id'] : null;
        }

        if (array_key_exists('title', $data) && $data['title'] === null) {
            unset($data['title']);
        }

        $before = $asset->only(array_keys($data));
        $asset->fill($data);
        $changes = Audit::diff($before, $asset->only(array_keys($data)));

        if ($changes !== []) {
            $asset->save();
            Audit::record($asset, 'media.updated', 'Edited "'.$asset->title.'"', $changes, $by->id);
        }

        return $asset;
    }
}
