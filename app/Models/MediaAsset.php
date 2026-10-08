<?php

namespace App\Models;

use App\Enums\Travel\MediaCategory;
use App\Enums\Travel\MediaUsagePermission;
use App\Enums\Travel\PackageStatus;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

/**
 * A file in the shared Media Gallery, uploaded once and reused on any number
 * of packages. Revoking permission stops future use without breaking
 * packages that already show it.
 */
#[Fillable([
    'disk', 'path', 'original_name', 'mime_type', 'size', 'width', 'height', 'kind',
    'title', 'description', 'alt_text', 'travel_provider_id', 'destination', 'category', 'tags',
    'source', 'copyright_owner', 'usage_permission', 'usage_notes', 'uploaded_by', 'archived_at',
])]
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => MediaCategory::class,
            'usage_permission' => MediaUsagePermission::class,
            'tags' => 'array',
            'size' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TravelProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(TravelProvider::class, 'travel_provider_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsToMany<Package, $this>
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'package_media')->withPivot(['position', 'is_primary'])->withTimestamps();
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function isImage(): bool
    {
        return $this->kind === 'image';
    }

    /**
     * May be added to a package now (not archived, permission not revoked).
     */
    public function isUsable(): bool
    {
        return $this->archived_at === null && $this->usage_permission !== MediaUsagePermission::Revoked;
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    #[Scope]
    protected function usable(Builder $query): void
    {
        $query->whereNull('archived_at')->where('usage_permission', '!=', MediaUsagePermission::Revoked);
    }

    /**
     * Title, description, file name and tags.
     *
     * @param  Builder<MediaAsset>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';

        $query->where(fn (Builder $query) => $query
            ->where('title', 'like', $like)
            ->orWhere('description', 'like', $like)
            ->orWhere('original_name', 'like', $like)
            ->orWhere('tags', 'like', '%'.str_replace('"', '', $term).'%'));
    }

    /**
     * Adds packages_count and published_packages_count.
     *
     * @param  Builder<MediaAsset>  $query
     */
    #[Scope]
    protected function withUsageCounts(Builder $query): void
    {
        $query->withCount([
            'packages',
            'packages as published_packages_count' => fn (Builder $packages) => $packages->where('packages.status', PackageStatus::Published),
        ]);
    }

    public function isRevoked(): bool
    {
        return $this->usage_permission === MediaUsagePermission::Revoked;
    }

    /**
     * How many packages show this file, and how many of those are published.
     *
     * @return array{packages: int, published: int}
     */
    public function usage(): array
    {
        if (array_key_exists('packages_count', $this->attributes)) {
            return ['packages' => (int) $this->attributes['packages_count'], 'published' => (int) ($this->attributes['published_packages_count'] ?? 0)];
        }

        return [
            'packages' => $this->packages()->count(),
            'published' => $this->packages()->where('packages.status', PackageStatus::Published)->count(),
        ];
    }

    public function sizeLabel(): string
    {
        $size = (int) $this->size;

        return match (true) {
            $size >= 1_048_576 => number_format($size / 1_048_576, 1).' MB',
            $size >= 1024 => number_format($size / 1024).' KB',
            default => $size.' B',
        };
    }
}
