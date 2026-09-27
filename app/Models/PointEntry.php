<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\PointEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in the points ledger. Point values never change after creation.
 */
#[Fillable([
    'user_id', 'partner_account_id', 'inventory_snapshot_id', 'type', 'points', 'earned_on', 'month',
    'bonus_week', 'status', 'reason', 'created_by', 'cancelled_at',
])]
class PointEntry extends Model
{
    /** @use HasFactory<PointEntryFactory> */
    use HasFactory;

    public const Types = [
        'base' => 'New Account',
        'expansion' => 'Expansion (higher category)',
        'half' => 'Expansion (same category, +50%)',
        'adjustment' => 'Adjustment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'float',
            'earned_on' => 'immutable_datetime',
            'bonus_week' => 'integer',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * Stored as a plain Y-m-d date for the first day of the month.
     *
     * @return Attribute<CarbonImmutable, mixed>
     */
    protected function month(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?CarbonImmutable => $value ? CarbonImmutable::parse($value)->startOfMonth() : null,
            set: fn (mixed $value): string => CarbonImmutable::parse($value)->startOfMonth()->toDateString(),
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PartnerAccount, $this>
     */
    public function partnerAccount(): BelongsTo
    {
        return $this->belongsTo(PartnerAccount::class);
    }

    /**
     * @return BelongsTo<InventorySnapshot, $this>
     */
    public function inventorySnapshot(): BelongsTo
    {
        return $this->belongsTo(InventorySnapshot::class);
    }

    public function typeLabel(): string
    {
        return self::Types[$this->type] ?? ucfirst($this->type);
    }

    /**
     * A line cancelled only because it was re-issued with new figures.
     */
    public function isReplaced(): bool
    {
        return $this->status === 'cancelled' && str_starts_with((string) $this->reason, 'Recalculated');
    }

    public function statusTone(): string
    {
        return match (true) {
            $this->isReplaced() => 'neutral',
            default => match ($this->status) {
                'approved' => 'success',
                'cancelled' => 'danger',
                default => 'warning',
            },
        };
    }

    public function statusLabel(): string
    {
        if ($this->isReplaced()) {
            return 'Replaced';
        }

        return match ($this->status) {
            'approved' => 'Approved',
            'cancelled' => 'Cancelled',
            default => 'Provisional',
        };
    }

    /**
     * @param  Builder<PointEntry>  $query
     */
    #[Scope]
    protected function counting(Builder $query): void
    {
        $query->where('status', '!=', 'cancelled');
    }

    /**
     * @param  Builder<PointEntry>  $query
     */
    #[Scope]
    protected function forMonth(Builder $query, CarbonInterface $month): void
    {
        $query->whereDate('month', $month->startOfMonth()->toDateString());
    }
}
