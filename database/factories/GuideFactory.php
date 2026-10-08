<?php

namespace Database\Factories;

use App\Enums\Travel\ResourceStatus;
use App\Models\Guide;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guide>
 */
class GuideFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '07'.fake()->numerify('########'),
            'languages' => ['English', 'Swahili'],
            'specialization' => fake()->randomElement(['Birding', 'Big Five', 'Culture', 'Hiking']),
            'status' => ResourceStatus::Active,
        ];
    }
}
