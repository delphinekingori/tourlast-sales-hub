<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Influencer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Influencer>
 */
class InfluencerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'phone' => '07'.fake()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'platform' => fake()->randomElement(['instagram', 'tiktok', 'youtube']),
            'handle' => '@'.strtolower(str_replace(' ', '', $name)),
            'owner_id' => User::factory()->withRole(Role::TravelSalesperson),
            'is_active' => true,
        ];
    }

    /**
     * Mirror the main platform into the platforms list, as SaveInfluencer does.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Influencer $influencer): void {
            if ($influencer->platform && ! $influencer->platforms()->exists()) {
                $influencer->platforms()->create(['platform' => $influencer->platform, 'handle' => $influencer->handle, 'position' => 0]);
            }
        });
    }
}
