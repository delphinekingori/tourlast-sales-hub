<?php

namespace Database\Factories;

use App\Models\SandboxProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SandboxProvider>
 */
class SandboxProviderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => 'SBX-'.fake()->unique()->numerify('#####'),
            'ref_code' => null,
            'property_name' => fake()->company().' Hotel',
            'property_type' => 'hotel',
            'location' => 'Nairobi, Kenya',
            'contact_name' => fake()->name(),
            'contact_phone' => '+2547'.fake()->numerify('########'),
            'contact_email' => fake()->unique()->safeEmail(),
            'status' => 'submitted',
            'submitted_at' => now()->subDays(2),
        ];
    }
}
