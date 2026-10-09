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
    'influencer_id', 'code', 'commission_type', 'commission_value', 'applies_to', 'max_bookings',
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
     * Commission on a booking amount under this code's terms.
     */
    public function commissionOn(float $amount): float
    {
        return round(match ($this->commission_type) {
            InfluencerCommissionType::Percentage => $amount * (float) $this->commission_value / 100,
            InfluencerCommissionType::Fixed => (float) $this->commission_value,
        }, 2);
    }

    public function termsLabel(): string
    {
        $value = $this->commission_type === InfluencerCommissionType::Percentage
            ? rtrim(rtrim(number_format((float) $this->commission_value, 2), '0'), '.').'%'
            : 'KES '.number_format((float) $this->commission_value);

        return $value.' per booking'.($this->max_bookings ? ', first '.$this->max_bookings.' bookings' : '');
    }
}
