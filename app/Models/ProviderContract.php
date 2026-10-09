<?php

namespace App\Models;

use App\Enums\Travel\CommissionModel;
use App\Enums\Travel\ContractStatus;
use Database\Factories\ProviderContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'contract_number', 'travel_provider_id', 'contract_type', 'starts_on', 'ends_on', 'status',
    'commission_model', 'commission_rate', 'fixed_commission', 'currency',
    'payment_terms', 'settlement_terms', 'cancellation_terms', 'refund_terms', 'notes',
    'created_by', 'approved_by', 'approved_at', 'last_expiry_alert_days',
])]
class ProviderContract extends Model
{
    /** @use HasFactory<ProviderContractFactory> */
    use HasFactory;

    /** Contracts end "soon" within this many days. */
    public const ExpiringSoonDays = 30;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => ContractStatus::class,
            'commission_model' => CommissionModel::class,
            'commission_rate' => 'decimal:2',
            'fixed_commission' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Next contract number such as TL-2026-007.
     */
    public static function nextNumber(): string
    {
        $prefix = 'TL-'.now()->year.'-';
        $last = static::query()->where('contract_number', 'like', $prefix.'%')->pluck('contract_number')
            ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        do {
            $number = $prefix.str_pad((string) ++$last, 3, '0', STR_PAD_LEFT);
        } while (static::query()->where('contract_number', $number)->exists());

        return $number;
    }

    /**
     * Filter by the status people see, including the worked-out "Expiring
     * soon" and "Expired" (active contracts near or past their end date).
     *
     * @param  Builder<ProviderContract>  $query
     */
    #[Scope]
    protected function withEffectiveStatus(Builder $query, ContractStatus $status): void
    {
        $soon = today()->addDays(self::ExpiringSoonDays)->toDateString();
        $today = today()->toDateString();

        match ($status) {
            ContractStatus::Active => $query->where('status', ContractStatus::Active)
                ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>', $soon)),
            ContractStatus::ExpiringSoon => $query->where('status', ContractStatus::Active)
                ->whereDate('ends_on', '>=', $today)->whereDate('ends_on', '<=', $soon),
            ContractStatus::Expired => $query->where('status', ContractStatus::Active)->whereDate('ends_on', '<', $today),
            default => $query->where('status', $status),
        };
    }

    /**
     * @return BelongsTo<TravelProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(TravelProvider::class, 'travel_provider_id');
    }

    /**
     * @return HasMany<ContractDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ContractDocument::class);
    }

    /**
     * Documents still in use (earlier versions and removed files excluded).
     *
     * @return HasMany<ContractDocument, $this>
     */
    public function currentDocuments(): HasMany
    {
        return $this->hasMany(ContractDocument::class)->current();
    }

    /**
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
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
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Active, started, and not past its end date.
     */
    public function isInForce(): bool
    {
        return $this->status === ContractStatus::Active
            && $this->starts_on->lte(today())
            && ($this->ends_on === null || $this->ends_on->gte(today()));
    }

    public function daysUntilExpiry(): ?int
    {
        return $this->ends_on ? (int) today()->diffInDays($this->ends_on, false) : null;
    }

    /**
     * The status to show: an active contract becomes "Expiring soon" in its
     * last 30 days and "Expired" after its end date.
     */
    public function effectiveStatus(): ContractStatus
    {
        if ($this->status !== ContractStatus::Active || $this->ends_on === null) {
            return $this->status;
        }

        $days = $this->daysUntilExpiry();

        return match (true) {
            $days < 0 => ContractStatus::Expired,
            $days <= self::ExpiringSoonDays => ContractStatus::ExpiringSoon,
            default => ContractStatus::Active,
        };
    }
}
