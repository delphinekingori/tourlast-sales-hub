<?php

namespace Database\Factories;

use App\Models\IncentiveAgreement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncentiveAgreement>
 */
class IncentiveAgreementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => null,
        ];
    }
}
