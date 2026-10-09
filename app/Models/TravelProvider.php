<?php

namespace App\Models;

use App\Enums\Travel\ContractStatus;
use App\Enums\Travel\TravelProviderStatus;
use App\Enums\Travel\TravelProviderType;
use Database\Factories\TravelProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tour, safari, experience, transport or other travel partner Tourlast
 * sells packages for. Acquisition history stays on the linked Property
 * Engagement Registry record; commission on a linked Partner Account.
 */
#[Fillable([
    'name', 'provider_type', 'business_name', 'trading_name', 'registration_number', 'kra_pin',
    'country', 'region', 'city', 'address', 'website', 'email', 'phone', 'whatsapp',
    'primary_contact_name', 'primary_contact_phone', 'primary_contact_email',
    'decision_maker_name', 'decision_maker_phone', 'description', 'status', 'owner_id',
    'property_engagement_id', 'partner_account_id', 'notes', 'created_by', 'updated_by', 'archived_at',
])]
class TravelProvider extends Model
{
    /** @use HasFactory<TravelProviderFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (TravelProvider $provider): void {
            $provider->name_key = PropertyEngagement::nameKey($provider->name);
            $provider->phone_key = PropertyEngagementContact::phoneKey($provider->phone);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_type' => TravelProviderType::class,
            'status' => TravelProviderStatus::class,
            'archived_at' => 'datetime',
        ];
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
     * @return BelongsTo<PropertyEngagement, $this>
     */
    public function propertyEngagement(): BelongsTo
    {
        return $this->belongsTo(PropertyEngagement::class);
    }

    /**
     * @return BelongsTo<PartnerAccount, $this>
     */
    public function partnerAccount(): BelongsTo
    {
        return $this->belongsTo(PartnerAccount::class);
    }

    /**
     * @return HasMany<ProviderContract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(ProviderContract::class);
    }

    /**
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    /**
     * @return HasMany<MediaAsset, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /**
     * @return HasMany<ProviderIncident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(ProviderIncident::class);
    }

    /**
     * @return HasMany<Driver, $this>
     */
    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    /**
     * @return HasMany<Guide, $this>
     */
    public function guides(): HasMany
    {
        return $this->hasMany(Guide::class);
    }

    /**
     * The contract currently in force (active and not past its end date).
     */
    public function activeContract(): ?ProviderContract
    {
        return $this->contracts
            ->filter(fn (ProviderContract $contract): bool => $contract->isInForce())
            ->sortByDesc('starts_on')
            ->first();
    }

    public function isActive(): bool
    {
        return in_array($this->status, [TravelProviderStatus::Active, TravelProviderStatus::Contracted], true)
            && $this->archived_at === null;
    }

    /**
     * @param  Builder<TravelProvider>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Providers the user works with: their own, or all for Travel managers.
     *
     * @param  Builder<TravelProvider>  $query
     */
    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->where('owner_id', $user->id);
    }

    /**
     * @param  Builder<TravelProvider>  $query
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
            ->orWhere('trading_name', 'like', $like)
            ->orWhere('business_name', 'like', $like)
            ->orWhere('city', 'like', $like)
            ->orWhere('primary_contact_name', 'like', $like)
            ->orWhere('email', 'like', $like));
    }

    /**
     * Providers with a contract in force today.
     *
     * @param  Builder<TravelProvider>  $query
     */
    #[Scope]
    protected function withContractInForce(Builder $query): void
    {
        $query->whereHas('contracts', fn (Builder $contracts) => $contracts
            ->where('status', ContractStatus::Active)
            ->whereDate('starts_on', '<=', today())
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', today())));
    }
}
