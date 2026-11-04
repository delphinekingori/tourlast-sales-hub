<?php

namespace App\Models;

use App\Enums\Travel\CommissionEntryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Commission an influencer earned on one booking (package or flight). Pending
 * until the booking is fully paid, payable after that, cancelled if the
 * booking is cancelled or refunded. One line per code and booking.
 */
#[Fillable([
    'influencer_code_id', 'influencer_id', 'bookable_type', 'bookable_id', 'booking_amount', 'commission_amount',
    'currency', 'status', 'earned_at', 'paid_at', 'paid_by', 'payment_reference',
])]
class InfluencerCommission extends Model
{
    /** Flight commission is always in USD, everything else in KES. Amounts are never converted between them. */
    public const FlightCurrency = 'USD';

    public const DefaultCurrency = 'KES';

    /** @var list<string> */
    public const Currencies = [self::DefaultCurrency, self::FlightCurrency];

    /**
     * The currency commission on this kind of booking is calculated and paid in.
     */
    public static function currencyFor(Model $bookable): string
    {
        return $bookable instanceof FlightBooking ? self::FlightCurrency : self::DefaultCurrency;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CommissionEntryStatus::class,
            'booking_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'earned_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InfluencerCode, $this>
     */
    public function code(): BelongsTo
    {
        return $this->belongsTo(InfluencerCode::class, 'influencer_code_id');
    }

    /**
     * @return BelongsTo<Influencer, $this>
     */
    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    /**
     * The PackageBooking or FlightBooking.
     *
     * @return MorphTo<Model, $this>
     */
    public function bookable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
