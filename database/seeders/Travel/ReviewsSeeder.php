<?php

namespace Database\Seeders\Travel;

use App\Enums\Travel\ReviewSource;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Models\TripReview;
use Illuminate\Database\Seeder;

/**
 * Demo client reviews (local only). Closes off a handful of confirmed bookings
 * as completed so there are trips to review, then records a spread of ratings
 * including one low rating and one hidden review.
 */
class ReviewsSeeder extends Seeder
{
    /** @var list<array{0: int, 1: string, 2: ReviewSource, 3: array<string, int>}> */
    private const Samples = [
        [5, 'Brilliant trip. The guide knew every animal and the lodge was spotless.', ReviewSource::Phone, ['rating_service' => 5, 'rating_guide' => 5, 'rating_value' => 4, 'rating_safety' => 5]],
        [4, 'Very good overall. Pickup was 20 minutes late but everything after that was smooth.', ReviewSource::WhatsApp, ['rating_service' => 4, 'rating_guide' => 5, 'rating_value' => 4]],
        [5, 'Worth every shilling. We would book again.', ReviewSource::Email, ['rating_value' => 5]],
        [3, 'Good game viewing but the vehicle was cramped for four adults.', ReviewSource::Phone, ['rating_service' => 3, 'rating_guide' => 4, 'rating_value' => 3]],
        [4, 'Lovely experience and a friendly driver.', ReviewSource::Form, []],
        [1, 'The driver did not show up on day two and we lost half a day. Poor communication.', ReviewSource::Phone, ['rating_service' => 1, 'rating_guide' => 2, 'rating_safety' => 2]],
        [2, 'Meals were not as described and the room was not ready on arrival.', ReviewSource::WhatsApp, ['rating_service' => 2, 'rating_value' => 2]],
        [5, 'Wonderful.', ReviewSource::InPerson, []],
    ];

    public function run(): void
    {
        if (TripReview::query()->exists()) {
            return;
        }

        $completed = PackageBooking::query()->where('status', TravelBookingStatus::Completed)->orderBy('id')->get();

        if ($completed->count() < count(self::Samples)) {
            $needed = count(self::Samples) - $completed->count();

            PackageBooking::query()->where('status', TravelBookingStatus::Confirmed)->orderBy('id')->limit($needed)->get()
                ->each(fn (PackageBooking $booking) => $booking->forceFill(['status' => TravelBookingStatus::Completed, 'completed_at' => now()->subDays(3)])->save());

            $completed = PackageBooking::query()->where('status', TravelBookingStatus::Completed)->orderBy('id')->get();
        }

        $completed->load('package')->take(count(self::Samples))->values()->each(function (PackageBooking $booking, int $i): void {
            [$rating, $comment, $source, $categories] = self::Samples[$i];

            TripReview::query()->create([
                ...$categories,
                'package_booking_id' => $booking->id,
                'package_id' => $booking->package_id,
                'travel_provider_id' => $booking->package?->travel_provider_id,
                'travel_client_id' => $booking->travel_client_id,
                'rating' => $rating,
                'comment' => $comment,
                'source' => $source,
                'recorded_by' => $booking->salesperson_id,
                'recorded_at' => now()->subDays(8 - $i),
                'is_hidden' => $i === 6,
                'hidden_reason' => $i === 6 ? 'Recorded against the wrong booking; re-entered by the salesperson.' : null,
                'moderated_at' => $i === 6 ? now()->subDay() : null,
            ]);
        });
    }
}
