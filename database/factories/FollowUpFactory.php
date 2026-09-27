<?php

namespace Database\Factories;

use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUp>
 */
class FollowUpFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'user_id' => User::factory(),
            'task' => fake()->randomElement(['Call the GM', 'Send the List Your Property link', 'Check signup progress', 'Visit the property']),
            'due_at' => now()->addDay()->startOfDay(),
        ];
    }
}
