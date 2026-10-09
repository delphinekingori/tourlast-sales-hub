<?php

namespace Database\Factories;

use App\Enums\Travel\CommissionModel;
use App\Enums\Travel\ContractStatus;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderContract>
 */
class ProviderContractFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_number' => 'TL-'.now()->year.'-'.fake()->unique()->numerify('####'),
            'travel_provider_id' => TravelProvider::factory(),
            'contract_type' => 'Commission agreement',
            'starts_on' => today()->subMonths(2),
            'ends_on' => today()->addYear(),
            'status' => ContractStatus::Active,
            'commission_model' => CommissionModel::Percentage,
            'commission_rate' => 12.5,
            'currency' => 'KES',
            'payment_terms' => 'Provider invoices Tourlast monthly; paid within 14 days.',
            'cancellation_terms' => 'Free cancellation up to 14 days before travel.',
            'refund_terms' => 'Refunds within 10 working days of approval.',
            'approved_at' => now()->subMonths(2),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => ContractStatus::Draft, 'approved_at' => null]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['starts_on' => today()->subYear(), 'ends_on' => today()->subDay()]);
    }

    public function endingIn(int $days): static
    {
        return $this->state(fn (): array => ['ends_on' => today()->addDays($days)]);
    }
}
