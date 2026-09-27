<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'business_name' => fake()->randomElement(['Kifaru', 'Duma', 'Tembo', 'Zawadi', 'Upendo', 'Bahari', 'Milele', 'Neema', 'Sunset', 'Riverside', 'Highland', 'Lakeview'])
                .' '.fake()->randomElement(['Hotel', 'Apartments', 'Safaris', 'Villas', 'Resort', 'Guest House', 'Lodge']),
            'property_type' => fake()->randomElement(['hotel', 'apartment', 'villa', 'resort', 'tour']),
            'location' => fake()->randomElement(['Nairobi', 'Mombasa', 'Diani', 'Naivasha', 'Kisumu']),
            'contact_name' => fake()->name(),
            'contact_role' => fake()->randomElement(['General Manager', 'Owner', 'Director', 'Sales Manager']),
            'contact_phone' => '+2547'.fake()->numerify('########'),
            'contact_email' => fake()->unique()->safeEmail(),
            'status' => LeadStatus::New,
        ];
    }
}
