<?php

namespace Database\Factories;

use App\Enums\Travel\ResourceStatus;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name('male'),
            'phone' => '07'.fake()->numerify('########'),
            'vehicle' => fake()->randomElement(['Toyota Land Cruiser', 'Safari minivan', 'Toyota Hiace']),
            'vehicle_registration' => 'K'.fake()->bothify('?? ###?'),
            'status' => ResourceStatus::Active,
        ];
    }
}
