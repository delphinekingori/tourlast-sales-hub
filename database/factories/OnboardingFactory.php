<?php

namespace Database\Factories;

use App\Enums\OnboardingStatus;
use App\Models\Onboarding;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Onboarding>
 */
class OnboardingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tourlast_property_id' => 'TL-'.fake()->unique()->numerify('#####'),
            'ref_code' => null,
            'referral_code_id' => null,
            'user_id' => null,
            'attribution' => 'referral',
            'property_name' => fake()->company().' '.fake()->randomElement(['Hotel', 'Suites', 'Apartments', 'Lodge', 'Villas']),
            'property_type' => fake()->randomElement(array_keys(config('hub.property_types'))),
            'location' => fake()->randomElement(['Nairobi', 'Mombasa', 'Diani', 'Naivasha', 'Kisumu', 'Nanyuki']).', Kenya',
            'contact_name' => fake()->name(),
            'contact_phone' => '+2547'.fake()->numerify('########'),
            'contact_email' => fake()->unique()->safeEmail(),
            'status' => OnboardingStatus::Submitted,
            'submitted_at' => now()->subDays(3),
        ];
    }

    /**
     * Credit the onboarding to a salesperson through their referral code.
     */
    public function forSalesperson(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'referral_code_id' => $user->referralCode?->id,
            'ref_code' => $user->referralCode?->code,
        ]);
    }

    public function approved(?\DateTimeInterface $at = null): static
    {
        $at ??= now()->subDay();

        return $this->state(fn (array $attributes) => [
            'status' => OnboardingStatus::Approved,
            'approved_at' => $at,
            'credited_at' => null,
        ]);
    }

    public function active(?\DateTimeInterface $at = null): static
    {
        $at ??= now()->subDay();

        return $this->state(fn (array $attributes) => [
            'status' => OnboardingStatus::Active,
            'approved_at' => $at,
            'active_at' => $at,
            'credited_at' => $at,
        ]);
    }

    /**
     * Went live on the Activation Date and stopped since: history that keeps
     * the credit it earned.
     */
    public function inactive(?\DateTimeInterface $at = null): static
    {
        $at ??= now()->subDay();
        $liveAt = now()->subMonth();

        return $this->state(fn (array $attributes) => [
            'status' => OnboardingStatus::Inactive,
            'approved_at' => $liveAt,
            'active_at' => $liveAt,
            'inactive_at' => $at,
            'credited_at' => $liveAt,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OnboardingStatus::Rejected,
            'rejected_at' => now(),
            'credited_at' => null,
        ]);
    }
}
