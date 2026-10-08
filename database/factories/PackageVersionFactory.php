<?php

namespace Database\Factories;

use App\Enums\Travel\PackageVersionStatus;
use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Complete content: passes the readiness check once it has itinerary days.
 *
 * @extends Factory<PackageVersion>
 */
class PackageVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'major' => 1,
            'minor' => 0,
            'status' => PackageVersionStatus::Draft,
            'name' => fn (array $attributes) => Package::find($attributes['package_id'])?->name ?? 'Masai Mara 3-Day Safari',
            'short_description' => 'Three days of game drives in the Masai Mara.',
            'description' => 'A classic road safari from Nairobi to the Masai Mara with two nights in a tented camp.',
            'package_type' => 'safari',
            'travel_provider_id' => fn (array $attributes) => Package::find($attributes['package_id'])?->travel_provider_id,
            'provider_contract_id' => fn (array $attributes) => Package::find($attributes['package_id'])?->provider_contract_id,
            'destination' => 'Masai Mara',
            'country' => 'Kenya',
            'region' => 'Narok',
            'start_location' => 'Nairobi',
            'end_location' => 'Nairobi',
            'duration_label' => '3 days, 2 nights',
            'days' => 3,
            'nights' => 2,
            'min_travelers' => 1,
            'max_travelers' => 7,
            'default_capacity' => 12,
            'overview' => 'Game drives morning and evening, all park fees included.',
            'highlights' => ['Big Five game drives', 'Mara River viewpoint'],
            'inclusions' => ['Park fees', 'Full board accommodation', 'Transport in a 4x4'],
            'exclusions' => ['Drinks', 'Tips', 'Balloon safari'],
            'cancellation_policy' => 'Full refund up to 14 days before departure; 50% up to 7 days; none after.',
            'refund_policy' => 'Refunds are paid within 10 working days.',
            'meeting_point' => 'Tourlast office, Westlands',
            'currency' => 'KES',
            'adult_price' => 45000,
            'child_price' => 30000,
            'infant_price' => 0,
            'provider_price' => 38000,
            'net_provider_price' => 38000,
            'commission_amount' => 7000,
            'created_by' => fn (array $attributes) => Package::find($attributes['package_id'])?->created_by,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => PackageVersionStatus::Approved, 'approved_at' => now(), 'submitted_at' => now()->subDay()]);
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => ['status' => PackageVersionStatus::Submitted, 'submitted_at' => now()]);
    }
}
