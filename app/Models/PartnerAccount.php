<?php

namespace App\Models;

use App\Incentives\ChecklistItem;
use App\Incentives\Policy;
use Carbon\CarbonImmutable;
use Database\Factories\PartnerAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One legal business on tourlast.com. Its properties share one set of points.
 */
#[Fillable([
    'account_key', 'legal_name', 'category', 'user_id', 'activation_date', 'activation_inventory',
    'inventory_basis', 'inventory_note', 'qualification_status', 'verified_by', 'verified_at',
    'review_failed_at', 'review_failed_reason', 'review_failed_by', 'review_warning_at', 'review_warning', 'merged_into_id',
])]
class PartnerAccount extends Model
{
    /** @use HasFactory<PartnerAccountFactory> */
    use HasFactory;

    public const Categories = ['stay' => 'Stay', 'experience' => 'Experience'];

    public const Bases = [
        'rooms' => 'Rooms', 'units' => 'Units', 'services' => 'Bookable services',
        'outlets' => 'Outlets or branches', 'packages' => 'Packages', 'products' => 'Independently bookable products',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activation_date' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'review_failed_at' => 'immutable_datetime',
            'review_warning_at' => 'immutable_datetime',
            'activation_inventory' => 'integer',
        ];
    }

    /**
     * The salesperson who onboarded the Account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Onboarding, $this>
     */
    public function onboardings(): HasMany
    {
        return $this->hasMany(Onboarding::class);
    }

    /**
     * @return HasMany<AccountChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(AccountChecklistItem::class);
    }

    /**
     * @return HasMany<InventorySnapshot, $this>
     */
    public function inventorySnapshots(): HasMany
    {
        return $this->hasMany(InventorySnapshot::class)->orderBy('live_on')->orderBy('id');
    }

    /**
     * @return HasMany<PointEntry, $this>
     */
    public function pointEntries(): HasMany
    {
        return $this->hasMany(PointEntry::class)->orderBy('earned_on')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * The incentive schedule in force on the Activation Date.
     */
    public function policy(): Policy
    {
        return IncentivePolicy::for($this->activation_date ?? now())->policy();
    }

    public function categoryLabel(): string
    {
        return self::Categories[$this->category] ?? 'Stay';
    }

    public function basisLabel(): string
    {
        return self::Bases[$this->inventory_basis] ?? 'Rooms';
    }

    public function isVerified(): bool
    {
        return $this->qualification_status === 'verified';
    }

    public function hasFailedReview(): bool
    {
        return $this->review_failed_at !== null;
    }

    public function reviewEndsAt(): ?CarbonImmutable
    {
        return $this->activation_date?->addDays($this->policy()->reviewDays());
    }

    public function isInReview(): bool
    {
        return $this->activation_date !== null && ! $this->hasFailedReview() && now()->lt($this->reviewEndsAt());
    }

    public function reviewStatus(): string
    {
        return match (true) {
            $this->activation_date === null => 'not_live',
            $this->hasFailedReview() => 'failed',
            $this->isInReview() => 'in_review',
            default => 'passed',
        };
    }

    public function expansionEndsAt(): ?CarbonImmutable
    {
        return $this->activation_date?->addDays($this->policy()->expansionDays());
    }

    public function expansionDaysLeft(): ?int
    {
        $end = $this->expansionEndsAt();

        return $end && now()->lt($end) ? (int) ceil(now()->diffInDays($end)) : null;
    }

    /**
     * Current live inventory: the latest snapshot, else the activation count.
     */
    public function currentInventory(): ?int
    {
        return $this->inventorySnapshots->last()?->count ?? $this->activation_inventory;
    }

    /**
     * Points still counting (not cancelled).
     */
    public function livePoints(): float
    {
        return (float) $this->pointEntries->where('status', '!=', 'cancelled')->sum('points');
    }

    /**
     * @return array{done: int, total: int}
     */
    public function checklistProgress(): array
    {
        return [
            'done' => $this->checklistItems->whereNotNull('completed_at')->count(),
            'total' => count(ChecklistItem::cases()),
        ];
    }

    public function checklistComplete(): bool
    {
        $progress = $this->checklistProgress();

        return $progress['done'] >= $progress['total'];
    }

    /**
     * @param  Builder<PartnerAccount>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('merged_into_id');
    }
}
