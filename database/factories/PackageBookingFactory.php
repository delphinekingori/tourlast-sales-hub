<?php

namespace Database\Factories;

use App\Enums\Travel\BookingPaymentStatus;
use App\Enums\Travel\BookingSource;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Models\PackageDeparture;
use App\Models\TravelClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending, unpaid booking for two adults on a published package departure.
 *
 * @extends Factory<PackageBooking>
 */
class PackageBookingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'TB-'.now()->year.'-'.fake()->unique()->numerify('####'),
            'package_departure_id' => PackageDeparture::factory(),
            'package_id' => fn (array $attributes) => PackageDeparture::find($attributes['package_departure_id'])->package_id,
            'package_version_id' => fn (array $attributes) => PackageDeparture::find($attributes['package_departure_id'])->package->live_version_id,
            'travel_client_id' => TravelClient::factory(),
            'salesperson_id' => fn (array $attributes) => PackageDeparture::find($attributes['package_departure_id'])->package->owner_id,
            'source' => BookingSource::Manual,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'currency' => 'KES',
            'amount_total' => 90000,
            'payment_status' => BookingPaymentStatus::Unpaid,
            'status' => TravelBookingStatus::Pending,
            'hold_expires_at' => now()->addHours(48),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => ['status' => TravelBookingStatus::Confirmed, 'confirmed_at' => now(), 'hold_expires_at' => null]);
    }

    public function paid(): static
    {
        return $this->confirmed()->state(fn (array $attributes): array => [
            'amount_paid' => $attributes['amount_total'],
            'payment_status' => BookingPaymentStatus::Paid,
        ]);
    }
}
