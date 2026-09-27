<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'month', 'incentive_policy_id', 'points', 'weekly_points', 'retainer', 'weekly_bonus',
    'monthly_bonus', 'exceptional', 'airtime', 'transport', 'adjustments', 'adjustment_lines', 'total',
    'compliance', 'status', 'approved_by', 'approved_at', 'paid_by', 'paid_at', 'payment_reference', 'recovered_amount',
])]
class PayoutStatement extends Model
{
    public const ComplianceItems = [
        'reports' => 'Required reports submitted',
        'training' => 'Required training completed',
        'follow_up' => 'Partner follow-up done',
        'support' => 'Partner support provided',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'float',
            'weekly_points' => 'array',
            'adjustment_lines' => 'array',
            'compliance' => 'array',
            'airtime' => 'float',
            'transport' => 'float',
            'adjustments' => 'float',
            'total' => 'float',
            'recovered_amount' => 'float',
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
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

    public function isCompliant(): bool
    {
        $compliance = $this->compliance ?? [];

        foreach (array_keys(self::ComplianceItems) as $item) {
            if (empty($compliance[$item])) {
                return false;
            }
        }

        return true;
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['approved', 'paid'], true);
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            'approved' => 'brand',
            'paid' => 'success',
            default => 'warning',
        };
    }
}
