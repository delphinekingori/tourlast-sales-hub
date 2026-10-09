<?php

namespace App\Actions\Travel\Media;

use App\Enums\Travel\MediaCategory;
use App\Enums\Travel\MediaUsagePermission;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\TravelAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores one uploaded file in the Media Gallery (public disk, media/YYYY/MM/)
 * with the metadata entered once for the batch.
 */
class StoreMediaAsset
{
    /** Allowed types: mime => [kind, max size in KB]. */
    public const Types = [
        'image/jpeg' => ['image', 8192],
        'image/png' => ['image', 8192],
        'image/webp' => ['image', 8192],
        'video/mp4' => ['video', 51200],
        'application/pdf' => ['document', 10240],
    ];

    /**
     * @param  array{title?: ?string, alt_text?: ?string, description?: ?string, travel_provider_id?: int|string|null, destination?: ?string, category?: ?string, tags?: list<string>|string|null, source?: ?string, copyright_owner?: ?string, usage_permission?: ?string, usage_notes?: ?string}  $meta
     */
    public function handle(UploadedFile $file, array $meta, User $by): MediaAsset
    {
        TravelAccess::abortUnlessWorks($by);

        $mime = (string) $file->getMimeType();

        if (! array_key_exists($mime, self::Types)) {
            throw ValidationException::withMessages(['file' => $file->getClientOriginalName().' is not a JPG, PNG, WebP, MP4 or PDF file.']);
        }

        [$kind, $maxKb] = self::Types[$mime];

        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages(['file' => $file->getClientOriginalName().' is larger than '.($maxKb / 1024).' MB.']);
        }

        $path = $file->store('media/'.now()->format('Y/m'), 'public');
        [$width, $height] = $kind === 'image' ? (@getimagesize($file->getRealPath()) ?: [null, null]) : [null, null];
        $title = trim((string) ($meta['title'] ?? '')) ?: self::titleFromFilename($file->getClientOriginalName());

        $asset = MediaAsset::query()->create([
            'disk' => 'public',
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $mime,
            'size' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
            'kind' => $kind,
            'title' => mb_substr($title, 0, 255),
            'description' => filled($meta['description'] ?? null) ? $meta['description'] : null,
            'alt_text' => trim((string) ($meta['alt_text'] ?? '')) ?: mb_substr($title, 0, 255),
            'travel_provider_id' => filled($meta['travel_provider_id'] ?? null) ? (int) $meta['travel_provider_id'] : null,
            'destination' => filled($meta['destination'] ?? null) ? trim((string) $meta['destination']) : null,
            'category' => MediaCategory::tryFrom((string) ($meta['category'] ?? '')) ?? ($kind === 'document' ? MediaCategory::Documents : MediaCategory::Other),
            'tags' => self::tags($meta['tags'] ?? null),
            'source' => filled($meta['source'] ?? null) ? $meta['source'] : null,
            'copyright_owner' => filled($meta['copyright_owner'] ?? null) ? $meta['copyright_owner'] : null,
            'usage_permission' => MediaUsagePermission::tryFrom((string) ($meta['usage_permission'] ?? '')) ?? MediaUsagePermission::Granted,
            'usage_notes' => filled($meta['usage_notes'] ?? null) ? $meta['usage_notes'] : null,
            'uploaded_by' => $by->id,
        ]);

        Audit::record($asset, 'media.uploaded', 'Uploaded "'.$asset->title.'" to the Media Gallery', userId: $by->id);

        return $asset;
    }

    public static function titleFromFilename(string $name): string
    {
        return Str::of(pathinfo($name, PATHINFO_FILENAME))->replace(['_', '-', '.'], ' ')->squish()->title()->toString() ?: 'Untitled';
    }

    /**
     * "Wildlife, lions , Mara" or ['Wildlife'] → ['wildlife', 'lions', 'mara'] (unique).
     *
     * @param  list<string>|string|null  $tags
     * @return list<string>|null
     */
    public static function tags(array|string|null $tags): ?array
    {
        $list = is_array($tags) ? $tags : explode(',', (string) $tags);
        $clean = array_values(array_unique(array_filter(array_map(fn ($tag): string => mb_strtolower(trim((string) $tag)), $list))));

        return $clean === [] ? null : $clean;
    }
}
