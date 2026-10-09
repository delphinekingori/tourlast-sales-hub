<?php

namespace App\Models;

use App\Enums\Travel\PackageStatus;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tour or experience package: the product. Its content and prices live in
 * numbered versions (PackageVersion). live_version_id is the approved version
 * customers see; working_version_id is a newer draft or pending version.
 *
 * Package status is the product lifecycle. While a published package has a
 * newer version under review, it stays Published (the live version keeps
 * selling) and workingVersion shows the pending change.
 *
 * Approved and Published are different things: a package needs Sales Admin
 * and Super Admin approval before it can be marked published, and the travel
 * salesperson publishes it on the right channel themselves.
 */
#[Fillable([
    'reference', 'name', 'package_type', 'destination', 'country', 'travel_provider_id', 'provider_contract_id',
    'status', 'live_version_id', 'working_version_id', 'owner_id', 'driver_id', 'guide_id', 'guide_required',
    'published_at', 'published_by', 'published_channel', 'published_url', 'unpublished_at',
    'contract_override_by', 'contract_override_reason', 'archived_at', 'created_by', 'updated_by',
])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    public const Types = [
        'safari' => 'Safari',
        'tour' => 'Tour',
        'day_trip' => 'Day trip',
        'experience' => 'Experience',
        'activity' => 'Activity',
        'beach' => 'Beach holiday',
        'city' => 'City break',
        'adventure' => 'Adventure',
        'cultural' => 'Cultural',
        'transfer' => 'Transfer',
        'other' => 'Other',
    ];

    protected static function booted(): void
    {
        static::saving(fn (Package $package) => $package->name_key = PropertyEngagement::nameKey($package->name));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PackageStatus::class,
            'guide_required' => 'boolean',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Next reference such as PKG-2026-0007.
     */
    public static function nextReference(): string
    {
        $prefix = 'PKG-'.now()->year.'-';
        $last = static::query()->where('reference', 'like', $prefix.'%')->max('reference');

        return $prefix.str_pad((string) ((int) substr((string) $last, -4) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return BelongsTo<TravelProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(TravelProvider::class, 'travel_provider_id');
    }

    /**
     * @return BelongsTo<ProviderContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProviderContract::class, 'provider_contract_id');
    }

    /**
     * @return HasMany<PackageVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PackageVersion::class);
    }

    /**
     * @return BelongsTo<PackageVersion, $this>
     */
    public function liveVersion(): BelongsTo
    {
        return $this->belongsTo(PackageVersion::class, 'live_version_id');
    }

    /**
     * @return BelongsTo<PackageVersion, $this>
     */
    public function workingVersion(): BelongsTo
    {
        return $this->belongsTo(PackageVersion::class, 'working_version_id');
    }

    /**
     * The version being edited or reviewed if there is one, else the live one.
     */
    public function latestVersion(): ?PackageVersion
    {
        return $this->workingVersion ?? $this->liveVersion;
    }

    /**
     * @return HasMany<PackageApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(PackageApproval::class);
    }

    /**
     * @return BelongsToMany<MediaAsset, $this>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'package_media')
            ->withPivot(['position', 'is_primary'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * @return HasMany<PackageDeparture, $this>
     */
    public function departures(): HasMany
    {
        return $this->hasMany(PackageDeparture::class);
    }

    /**
     * @return HasMany<PackageBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(PackageBooking::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<Guide, $this>
     */
    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class);
    }

    public function typeLabel(): string
    {
        return self::Types[$this->package_type] ?? ucfirst((string) $this->package_type);
    }

    /**
     * Has an approved version that can be sold.
     */
    public function isSellable(): bool
    {
        return $this->live_version_id !== null
            && in_array($this->status, [PackageStatus::Approved, PackageStatus::Published], true)
            && $this->archived_at === null;
    }

    /**
     * @param  Builder<Package>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<Package>  $query
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
            ->where('name', 'like', $like)
            ->orWhere('reference', 'like', $like)
            ->orWhere('destination', 'like', $like)
            ->orWhereHas('provider', fn (Builder $provider) => $provider->where('name', 'like', $like)));
    }
}
