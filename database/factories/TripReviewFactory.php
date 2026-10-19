<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\Travel\ReviewSource;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Models\TripReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A visible review on a completed booking.
 *
 * @extends Factory<TripReview>
 */
class TripReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_booking_id' => PackageBooking::factory()->state([
                'status' => TravelBookingStatus::Completed,
                'confirmed_at' => now()->subDays(10),
                'completed_at' => now()->subDay(),
                'hold_expires_at' => null,
            ]),
            'package_id' => fn (array $attributes) => PackageBooking::find($attributes['package_booking_id'])->package_id,
            'travel_provider_id' => fn (array $attributes) => PackageBooking::find($attributes['package_booking_id'])->package->travel_provider_id,
            'travel_client_id' => fn (array $attributes) => PackageBooking::find($attributes['package_booking_id'])->travel_client_id,
            'rating' => fake()->numberBetween(3, 5),
            'comment' => fake()->sentence(),
            'source' => ReviewSource::Phone,
            'recorded_by' => fn (array $attributes) => PackageBooking::find($attributes['package_booking_id'])->salesperson_id ?? User::factory()->withRole(Role::TravelSalesperson),
            'recorded_at' => now(),
            'is_hidden' => false,
        ];
    }

    public function rated(int $rating): static
    {
        return $this->state(['rating' => $rating]);
    }

    public function hidden(string $reason = 'Spam'): static
    {
        return $this->state(['is_hidden' => true, 'hidden_reason' => $reason, 'moderated_at' => now()]);
    }
}
