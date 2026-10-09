<?php

namespace Database\Factories;

use App\Enums\Travel\GuestType;
use App\Models\PackageBooking;
use App\Models\PackageBookingGuest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An adult guest with a name only.
 *
 * @extends Factory<PackageBookingGuest>
 */
class PackageBookingGuestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_booking_id' => PackageBooking::factory(),
            'position' => 1,
            'full_name' => fake()->name(),
            'type' => GuestType::Adult,
            'is_booker' => false,
        ];
    }
}
