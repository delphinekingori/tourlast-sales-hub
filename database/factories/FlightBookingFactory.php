<?php

namespace Database\Factories;

use App\Models\FlightBooking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A confirmed, paid one-way booking as synced from Flights Super Admin.
 *
 * @extends Factory<FlightBooking>
 */
class FlightBookingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $departure = now()->addDays(fake()->numberBetween(3, 40))->setTime(fake()->numberBetween(6, 20), 15);
        [$origin, $destination] = fake()->randomElement([['NBO', 'MBA'], ['NBO', 'KIS'], ['MBA', 'NBO'], ['NBO', 'EBB'], ['NBO', 'DXB']]);
        $total = fake()->numberBetween(9, 90) * 1000;

        return [
            'source_system' => 'tourlast-flights',
            'external_id' => 'FL-'.fake()->unique()->numerify('######'),
            'booking_reference' => 'TLF'.fake()->unique()->numerify('#####'),
            'pnr' => strtoupper(fake()->bothify('??#??#')),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => '07'.fake()->numerify('########'),
            'airline_code' => 'KQ',
            'airline_name' => 'Kenya Airways',
            'origin' => $origin,
            'destination' => $destination,
            'trip_type' => 'one_way',
            'cabin' => 'economy',
            'departure_at' => $departure,
            'arrival_at' => $departure->copy()->addHour(),
            'passenger_count' => 1,
            'currency' => 'KES',
            'fare_amount' => $total * 0.9,
            'total_amount' => $total,
            'markup_amount' => $total * 0.05,
            'booking_status' => 'confirmed',
            'payment_status' => 'paid',
            'booked_at' => now()->subDays(fake()->numberBetween(0, 20)),
            'last_synced_at' => now(),
            'sync_status' => 'synced',
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'booking_status' => 'cancelled',
            'cancellation_status' => 'cancelled',
            'cancelled_at' => now()->subDay(),
            'cancellation_reason' => 'Customer request',
            'refund_status' => 'pending',
            'refund_amount' => 8000,
            'refund_requested_at' => now()->subDay(),
        ]);
    }
}
