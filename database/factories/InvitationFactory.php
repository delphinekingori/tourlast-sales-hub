<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+2547'.fake()->numerify('########'),
            'role' => Role::Salesperson,
            'region' => fake()->randomElement(['Nairobi', 'Coast', 'Western', 'Rift Valley']),
            'token_hash' => Invitation::hashToken(Str::random(64)),
            'invited_by' => User::factory(),
            'expires_at' => now()->addDays(7),
            'last_sent_at' => now(),
        ];
    }

    /**
     * Use a known plain token so tests can open the link.
     */
    public function withToken(string $plainToken): static
    {
        return $this->state(fn (array $attributes) => [
            'token_hash' => Invitation::hashToken($plainToken),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
            'last_sent_at' => now()->subDays(8),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }
}
