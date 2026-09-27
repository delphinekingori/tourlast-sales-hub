<?php

namespace Database\Factories;

use App\Models\ReferralClick;
use App\Models\ReferralCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralClick>
 */
class ReferralClickFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'referral_code_id' => ReferralCode::factory(),
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'user_agent' => fake()->userAgent(),
            'clicked_at' => now()->subHours(fake()->numberBetween(1, 100)),
        ];
    }
}
