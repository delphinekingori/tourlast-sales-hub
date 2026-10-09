<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\Travel\TravelProviderStatus;
use App\Enums\Travel\TravelProviderType;
use App\Models\TravelProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TravelProvider>
 */
class TravelProviderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Savannah', 'Acacia', 'Rift', 'Kilima', 'Tsavo', 'Maasai', 'Coral', 'Baobab', 'Simba', 'Twiga', 'Jambo', 'Safari'])
            .' '.fake()->unique()->bothify('?? ##');

        return [
            'name' => $name.' Tours',
            'provider_type' => fake()->randomElement([TravelProviderType::TourOperator, TravelProviderType::SafariOperator, TravelProviderType::ExperienceProvider]),
            'business_name' => $name.' Tours Limited',
            'country' => 'Kenya',
            'region' => fake()->randomElement(['Nairobi', 'Coast', 'Rift Valley', 'Central']),
            'city' => fake()->randomElement(['Nairobi', 'Mombasa', 'Nakuru', 'Narok']),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '07'.fake()->numerify('########'),
            'primary_contact_name' => fake()->name(),
            'status' => TravelProviderStatus::Active,
            'owner_id' => User::factory()->withRole(Role::TravelSalesperson),
        ];
    }

    public function prospect(): static
    {
        return $this->state(fn (): array => ['status' => TravelProviderStatus::Prospect]);
    }
}
