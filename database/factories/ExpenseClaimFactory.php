<?php

namespace Database\Factories;

use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseClaim>
 */
class ExpenseClaimFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'transport_reimbursement',
            'month' => now()->startOfMonth()->toDateString(),
            'amount' => 800,
            'description' => 'Site visit',
            'travel_date' => now()->toDateString(),
            'ride_provider' => 'bolt',
            'trip_reference' => 'RB12345678',
            'pickup' => 'Westlands',
            'dropoff' => 'Kilimani',
            'status' => 'pending',
            'current_step' => 'manager',
        ];
    }
}
