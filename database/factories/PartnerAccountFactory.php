<?php

namespace Database\Factories;

use App\Models\PartnerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerAccount>
 */
class PartnerAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_key' => 'A:'.fake()->unique()->numerify('H-#####'),
            'legal_name' => fake()->company().' Ltd',
            'category' => 'stay',
            'inventory_basis' => 'rooms',
            'qualification_status' => 'pending',
        ];
    }
}
