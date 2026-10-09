<?php

namespace Database\Factories;

use App\Enums\Travel\InfluencerCodeScope;
use App\Enums\Travel\InfluencerCodeStatus;
use App\Enums\Travel\InfluencerCommissionType;
use App\Models\Influencer;
use App\Models\InfluencerCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 10% on the first 20 package bookings over three months.
 *
 * @extends Factory<InfluencerCode>
 */
class InfluencerCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'influencer_id' => Influencer::factory(),
            'code' => 'INF-'.strtoupper(fake()->unique()->bothify('????##')),
            'commission_type' => InfluencerCommissionType::Percentage,
            'commission_value' => 10,
            'applies_to' => InfluencerCodeScope::Packages,
            'max_bookings' => 20,
            'starts_on' => today()->subWeek(),
            'ends_on' => today()->addMonths(3),
            'status' => InfluencerCodeStatus::Active,
            'created_by' => fn (array $attributes) => Influencer::find($attributes['influencer_id'])->owner_id,
        ];
    }
}
