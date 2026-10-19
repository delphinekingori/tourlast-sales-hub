<?php

namespace App\Actions\Travel\Reviews;

use App\Enums\Travel\ReviewSource;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Models\TripReview;
use App\Models\User;
use App\Support\Alerts;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Records the client's feedback on a completed trip, on the client's behalf.
 * The booking's salesperson, the package owner or a Travel manager may record
 * it; there is one review per booking. A rating of 2 or below sends a Smart
 * Alert to Travel managers.
 */
class RecordTripReview
{
    /**
     * @param  array<string, mixed>  $data  rating (1-5), source, optional rating_service / rating_guide / rating_value / rating_safety, comment.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, PackageBooking $booking, array $data): TripReview
    {
        abort_unless($booking->isWorkableBy($actor), 403);

        if ($booking->status !== TravelBookingStatus::Completed) {
            throw ValidationException::withMessages(['rating' => 'Only a completed booking can be reviewed.']);
        }

        if ($booking->review()->exists()) {
            throw ValidationException::withMessages(['rating' => 'This booking already has a review.']);
        }

        $score = ['integer', 'between:1,5'];
        $valid = Validator::make($data, [
            'rating' => ['required', ...$score],
            'rating_service' => ['nullable', ...$score],
            'rating_guide' => ['nullable', ...$score],
            'rating_value' => ['nullable', ...$score],
            'rating_safety' => ['nullable', ...$score],
            'comment' => ['nullable', 'string', 'max:2000'],
            'source' => ['required', Rule::enum(ReviewSource::class)],
        ])->validate();

        $booking->loadMissing('package.provider', 'client');

        try {
            $review = DB::transaction(fn (): TripReview => TripReview::query()->create([
                ...$valid,
                'comment' => filled($valid['comment'] ?? null) ? trim($valid['comment']) : null,
                'package_booking_id' => $booking->id,
                'package_id' => $booking->package_id,
                'travel_provider_id' => $booking->package?->travel_provider_id,
                'travel_client_id' => $booking->travel_client_id,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['rating' => 'This booking already has a review.']);
        }

        Audit::record($booking, 'review.recorded', "Review recorded for {$booking->reference}: {$review->rating}/5", userId: $actor->id);

        if ($review->isLow()) {
            $provider = $booking->package?->provider?->name ?? $booking->package?->name;

            Alerts::sendTravel(
                'low_trip_review',
                "Low trip rating: {$review->rating}/5 for {$provider}",
                "{$booking->client?->name} rated {$booking->reference} ({$booking->package?->name}) {$review->rating}/5".($review->comment ? ': '.str($review->comment)->limit(160) : '.'),
                route('travel.reviews.index', ['rating' => 'low']),
            );
        }

        return $review;
    }
}
