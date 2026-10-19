<?php

namespace App\Models;

use App\Enums\Travel\ReviewSource;
use Database\Factories\TripReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's feedback on a completed trip, recorded by staff on their behalf
 * (one per booking). Staff may hide a review with a reason; hidden reviews
 * stay on file but no longer count towards provider or package ratings.
 */
#[Fillable([
    'package_booking_id', 'package_id', 'travel_provider_id', 'travel_client_id', 'rating', 'rating_service',
    'rating_guide', 'rating_value', 'rating_safety', 'comment', 'source', 'recorded_by', 'recorded_at',
    'is_hidden', 'hidden_reason', 'moderated_by', 'moderated_at',
])]
class TripReview extends Model
{
    /** @use HasFactory<TripReviewFactory> */
    use HasFactory;

    /** A rating at or below this raises a Smart Alert for Travel managers. */
    public const LowRating = 2;

    /** Optional category ratings: column => label. */
    public const Categories = [
        'rating_service' => 'Provider / service',
        'rating_guide' => 'Guide / driver',
        'rating_value' => 'Value for money',
        'rating_safety' => 'Safety',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ReviewSource::class,
            'rating' => 'integer',
            'rating_service' => 'integer',
            'rating_guide' => 'integer',
            'rating_value' => 'integer',
            'rating_safety' => 'integer',
            'recorded_at' => 'datetime',
            'is_hidden' => 'boolean',
            'moderated_at' => 'datetime',
        ];
    }

    public function isLow(): bool
    {
        return $this->rating <= self::LowRating;
    }

    /**
     * @return BelongsTo<PackageBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(PackageBooking::class, 'package_booking_id');
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
     * @return BelongsTo<TravelClient, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(TravelClient::class, 'travel_client_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /**
     * Reviews that count towards ratings.
     *
     * @param  Builder<TripReview>  $query
     */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->where('is_hidden', false);
    }
}
