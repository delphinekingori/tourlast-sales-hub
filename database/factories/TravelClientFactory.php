<?php

namespace Database\Factories;

use App\Models\TravelClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TravelClient>
 */
class TravelClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '07'.fake()->unique()->numerify('########'),
            'country' => 'Kenya',
        ];
    }
}
