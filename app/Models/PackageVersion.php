<?php

namespace App\Models;

use App\Enums\Travel\PackageVersionStatus;
use Database\Factories\PackageVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a package's content and prices. Approved versions are never
 * edited again: changing a material field on an approved package creates the
 * next minor version (1.0 → 1.1), which must be approved before it replaces
 * the live one.
 */
#[Fillable([
    'package_id', 'major', 'minor', 'status',
    'name', 'short_description', 'description', 'package_type', 'travel_provider_id', 'provider_contract_id',
    'destination', 'country', 'region', 'start_location', 'end_location', 'duration_label', 'days', 'nights',
    'difficulty', 'min_travelers', 'max_travelers', 'default_capacity', 'min_age', 'age_notes',
    'overview', 'highlights', 'inclusions', 'exclusions', 'requirements', 'what_to_bring', 'terms',
    'cancellation_policy', 'refund_policy', 'meeting_point', 'pickup_info', 'dropoff_info',
    'currency', 'adult_price', 'child_price', 'infant_price', 'group_price', 'group_min_size', 'single_supplement',
    'provider_price', 'net_provider_price', 'discount_amount', 'commission_amount',
    'material_changes', 'change_note', 'submitted_at', 'submitted_by', 'approved_at', 'created_by', 'updated_by',
])]
class PackageVersion extends Model
{
    /** @use HasFactory<PackageVersionFactory> */
    use HasFactory;

    /**
     * Fields that, when changed on an approved package, require re-approval.
     * Itinerary changes are material too (compared separately).
     */
    public const MaterialFields = [
        'adult_price', 'child_price', 'infant_price', 'group_price', 'single_supplement', 'currency', 'discount_amount',
        'travel_provider_id', 'provider_contract_id', 'default_capacity', 'max_travelers',
        'cancellation_policy', 'refund_policy', 'inclusions', 'exclusions', 'days', 'nights', 'destination',
    ];

    /** Cost and commission fields, hidden without "view travel financials". */
    public const FinancialFields = ['provider_price', 'net_provider_price', 'commission_amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PackageVersionStatus::class,
            'highlights' => 'array',
            'inclusions' => 'array',
            'exclusions' => 'array',
            'what_to_bring' => 'array',
            'material_changes' => 'array',
            'adult_price' => 'decimal:2',
            'child_price' => 'decimal:2',
            'infant_price' => 'decimal:2',
            'group_price' => 'decimal:2',
            'single_supplement' => 'decimal:2',
            'provider_price' => 'decimal:2',
            'net_provider_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
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
     * @return HasMany<PackageItineraryDay, $this>
     */
    public function itineraryDays(): HasMany
    {
        return $this->hasMany(PackageItineraryDay::class)->orderBy('day_number');
    }

    /**
     * @return HasMany<PackageApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(PackageApproval::class)->orderBy('decided_at');
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
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function label(): string
    {
        return 'v'.$this->major.'.'.$this->minor;
    }

    /**
     * Still editable: a draft, or sent back for changes or rejected.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [PackageVersionStatus::Draft, PackageVersionStatus::ChangesRequested, PackageVersionStatus::Rejected], true);
    }

    public function isAwaitingReview(): bool
    {
        return in_array($this->status, [PackageVersionStatus::Submitted, PackageVersionStatus::SalesAdminApproved], true);
    }

    /**
     * Selling price minus net provider price (null when either is unknown).
     */
    public function margin(): ?float
    {
        if ($this->adult_price === null || $this->net_provider_price === null) {
            return null;
        }

        return round((float) $this->adult_price - (float) $this->discount_amount - (float) $this->net_provider_price, 2);
    }

    /**
     * Price for a party, from the per-person prices (group price when the
     * party is large enough).
     */
    public function priceFor(int $adults, int $children = 0, int $infants = 0): float
    {
        $travelers = $adults + $children + $infants;

        if ($this->group_price !== null && $this->group_min_size && $travelers >= $this->group_min_size) {
            return round((float) $this->group_price * ($adults + $children), 2);
        }

        $total = $adults * (float) $this->adult_price
            + $children * (float) ($this->child_price ?? $this->adult_price)
            + $infants * (float) ($this->infant_price ?? 0);

        return round(max(0, $total - (float) $this->discount_amount * ($adults + $children)), 2);
    }
}
