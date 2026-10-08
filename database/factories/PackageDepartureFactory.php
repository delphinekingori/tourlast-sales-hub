<?php

namespace Database\Factories;

use App\Enums\Travel\DepartureStatus;
use App\Enums\Travel\TripStatus;
use App\Models\Package;
use App\Models\PackageDeparture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageDeparture>
 */
class PackageDepartureFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = today()->addDays(fake()->numberBetween(10, 60));

        return [
            'package_id' => Package::factory()->published(),
            'starts_on' => $start,
            'start_time' => '07:00',
            'ends_on' => $start->copy()->addDays(2),
            'capacity' => 12,
            'status' => DepartureStatus::Open,
            'trip_status' => TripStatus::Scheduled,
        ];
    }
}
