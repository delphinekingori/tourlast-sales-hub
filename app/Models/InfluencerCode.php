<?php

namespace App\Models;

use App\Enums\Travel\InfluencerCodeScope;
use App\Enums\Travel\InfluencerCodeStatus;
use App\Enums\Travel\InfluencerCommissionType;
use Carbon\CarbonInterface;
use Database\Factories\InfluencerCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A referral code given to an influencer. A booking made with the code earns
 * the influencer commission (a percentage of the booking or a fixed amount)
 * while the code is active, within its dates, and until it has earned on
 * max_bookings bookings (no limit when null).
 */
#[Fillable([
    'influencer_id', 'code', 'commission_type', 'commission_value', 'fixed_kes', 'fixed_usd', 'applies_to', 'max_bookings',
    'starts_on', 'ends_on', 'status', 'created_by',
])]
class InfluencerCode extends Model
{
    /** @use HasFactory<InfluencerCodeFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(fn (InfluencerCode $code) => $code->code = strtoupper(trim((string) $code->code)));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commission_type' => InfluencerCommissionType::class,
            'commission_value' => 'decimal:2',
            'fixed_kes' => 'decimal:2',
            'fixed_usd' => 'decimal:2',
            'applies_to' => InfluencerCodeScope::class,
            'status' => InfluencerCodeStatus::class,
            'max_bookings' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Influencer, $this>
     */
    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<InfluencerCommission, $this>
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(InfluencerCommission::class);
    }

    /**
     * @return HasMany<PackageBooking, $this>
     */
    public function packageBookings(): HasMany
    {
        return $this->hasMany(PackageBooking::class);
    }

    /**
     * @return HasMany<FlightBooking, $this>
     */
    public function flightBookings(): HasMany
    {
        return $this->hasMany(FlightBooking::class);
    }

    /**
     * Within its dates on the given day and not paused or ended.
     */
    public function isRunningOn(CarbonInterface $day): bool
    {
        return $this->status === InfluencerCodeStatus::Active
            && $this->starts_on->lte($day)
            && ($this->ends_on === null || $this->ends_on->gte($day));
    }

    public function bookingsRemaining(): ?int
    {
        if ($this->max_bookings === null) {
            return null;
        }

        $used = $this->commissions()->where('status', '!=', 'cancelled')->count();

        return max(0, $this->max_bookings - $used);
    }

    /**
     * Commission on a booking amount under this code's terms, in the booking's
     * own currency. A fixed code with no amount set for that currency earns
     * nothing (null): amounts are never converted.
     */
    public function commissionOn(float $amount, string $currency = InfluencerCommission::DefaultCurrency): ?float
    {
        if ($this->commission_type === InfluencerCommissionType::Percentage) {
            return round($amount * (float) $this->commission_value / 100, 2);
        }

        return $this->fixedAmountIn($currency);
    }

    /**
     * The fixed amount the admin set for a currency, or null when none is set.
     */
    public function fixedAmountIn(string $currency): ?float
    {
        $value = match (strtoupper($currency)) {
            'KES' => $this->fixed_kes,
            'USD' => $this->fixed_usd,
            default => null,
        };

        return $value === null ? null : round((float) $value, 2);
    }

    /**
     * Fixed amounts that are set, keyed by currency: ['KES' => 500.0, 'USD' => 25.0].
     *
     * @return array<string, float>
     */
    public function fixedAmounts(): array
    {
        return collect(InfluencerCommission::Currencies)
            ->mapWithKeys(fn (string $currency): array => [$currency => $this->fixedAmountIn($currency)])
            ->filter(fn (?float $value): bool => $value !== null)
            ->all();
    }

    public function termsLabel(): string
    {
        $value = $this->commission_type === InfluencerCommissionType::Percentage
            ? rtrim(rtrim(number_format((float) $this->commission_value, 2), '0'), '.').'% of the gross booking total'
            : collect($this->fixedAmounts())->map(fn (float $amount, string $currency): string => $currency.' '.number_format($amount, 2))->implode(' / ').' per booking';

        return $value.($this->max_bookings ? ', first '.$this->max_bookings.' bookings' : '');
    }

    /**
     * Sums loaded by InfluencerCodeFilters, per currency: ['KES' => 1200.0, 'USD' => 0.0].
     *
     * @return array<string, float>
     */
    public function amountsByCurrency(string $name): array
    {
        return collect(InfluencerCommission::Currencies)
            ->mapWithKeys(fn (string $currency): array => [$currency => round((float) ($this->getAttribute($name.'_'.strtolower($currency)) ?? 0), 2)])
            ->all();
    }
}
