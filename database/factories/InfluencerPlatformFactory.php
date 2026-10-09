<?php

namespace Database\Factories;

use App\Models\Influencer;
use App\Models\InfluencerPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InfluencerPlatform>
 */
class InfluencerPlatformFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'influencer_id' => Influencer::factory(),
            'platform' => fake()->randomElement(array_keys(Influencer::Platforms)),
            'handle' => '@'.fake()->userName(),
            'url' => null,
            'position' => 0,
        ];
    }
}
