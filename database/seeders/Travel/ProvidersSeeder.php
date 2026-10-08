<?php

namespace Database\Seeders\Travel;

use App\Enums\Role;
use App\Enums\Travel\CommissionModel;
use App\Enums\Travel\ContractStatus;
use App\Enums\Travel\IncidentSeverity;
use App\Enums\Travel\IncidentStatus;
use App\Enums\Travel\IncidentType;
use App\Enums\Travel\TravelProviderStatus;
use App\Enums\Travel\TravelProviderType;
use App\Models\ProviderContract;
use App\Models\ProviderIncident;
use App\Models\TravelProvider;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo travel providers, contracts (one expiring soon, one expired) and incidents.
 */
class ProvidersSeeder extends Seeder
{
    public function run(): void
    {
        $aisha = $this->salesperson('aisha@tourlast.test', 'Aisha Njeri');
        $kevin = $this->salesperson('kevin@tourlast.test', 'Kevin Otieno');

        $providers = [
            ['Mara Horizon Safaris', TravelProviderType::SafariOperator, 'Narok', 'Rift Valley', TravelProviderStatus::Active, $aisha, 'active'],
            ['Amboseli Trails Ltd', TravelProviderType::TourOperator, 'Kajiado', 'Rift Valley', TravelProviderStatus::Active, $aisha, 'expiring'],
            ['Diani Ocean Adventures', TravelProviderType::AdventureCompany, 'Diani', 'Coast', TravelProviderStatus::Active, $kevin, 'active'],
            ['Naivasha Boat & Bike', TravelProviderType::ActivityProvider, 'Naivasha', 'Rift Valley', TravelProviderStatus::Contracted, $kevin, 'active'],
            ['Tsavo Wild Expeditions', TravelProviderType::SafariOperator, 'Voi', 'Coast', TravelProviderStatus::Suspended, $aisha, 'expired'],
            ['Nairobi City Walks', TravelProviderType::ExperienceProvider, 'Nairobi', 'Nairobi', TravelProviderStatus::Active, $kevin, 'active'],
            ['Rift Valley Transfers', TravelProviderType::TransportProvider, 'Nakuru', 'Rift Valley', TravelProviderStatus::Negotiation, $aisha, 'draft'],
            ['Lamu Dhow Dining', TravelProviderType::Dining, 'Lamu', 'Coast', TravelProviderStatus::Prospect, $kevin, null],
        ];

        foreach ($providers as $index => [$name, $type, $city, $region, $status, $owner, $contractState]) {
            $provider = TravelProvider::query()->firstOrCreate(['name' => $name], [
                'provider_type' => $type,
                'business_name' => $name.' Limited',
                'country' => 'Kenya',
                'region' => $region,
                'city' => $city,
                'email' => 'bookings@'.str($name)->slug()->replace('-', '').'.co.ke',
                'phone' => '0722'.str_pad((string) (410000 + $index * 1371), 6, '0', STR_PAD_LEFT),
                'primary_contact_name' => fake()->name(),
                'primary_contact_phone' => '0733'.str_pad((string) (520000 + $index * 977), 6, '0', STR_PAD_LEFT),
                'description' => $type->label().' based in '.$city.'.',
                'status' => $status,
                'owner_id' => $owner->id,
                'created_by' => $owner->id,
            ]);

            if ($contractState === null || $provider->contracts()->exists()) {
                continue;
            }

            [$starts, $ends, $contractStatus] = match ($contractState) {
                'expiring' => [today()->subYear()->addDays(10), today()->addDays(10), ContractStatus::Active],
                'expired' => [today()->subYear()->subMonth(), today()->subMonth(), ContractStatus::Active],
                'draft' => [today(), today()->addYear(), ContractStatus::Draft],
                default => [today()->subMonths(3), today()->addMonths(9), ContractStatus::Active],
            };

            ProviderContract::query()->create([
                'contract_number' => ProviderContract::nextNumber(),
                'travel_provider_id' => $provider->id,
                'contract_type' => 'Commission agreement',
                'starts_on' => $starts,
                'ends_on' => $ends,
                'status' => $contractStatus,
                'commission_model' => CommissionModel::Percentage,
                'commission_rate' => 10 + $index,
                'currency' => 'KES',
                'payment_terms' => 'Provider invoices Tourlast monthly; paid within 14 days.',
                'cancellation_terms' => 'Free cancellation up to 14 days before travel; 50% up to 7 days.',
                'refund_terms' => 'Refunds paid within 10 working days.',
                'created_by' => $owner->id,
                'approved_by' => $contractStatus === ContractStatus::Active ? User::query()->role(Role::SuperAdmin->value)->value('id') : null,
                'approved_at' => $contractStatus === ContractStatus::Active ? $starts : null,
            ]);
        }

        $tsavo = TravelProvider::query()->where('name', 'Tsavo Wild Expeditions')->first();
        $diani = TravelProvider::query()->where('name', 'Diani Ocean Adventures')->first();

        if ($tsavo && ! $tsavo->incidents()->exists()) {
            ProviderIncident::query()->create([
                'travel_provider_id' => $tsavo->id,
                'occurred_on' => today()->subDays(12),
                'type' => IncidentType::DriverNoShow,
                'severity' => IncidentSeverity::High,
                'description' => 'Driver did not arrive for the 6am pickup in Mombasa; guests waited two hours.',
                'status' => IncidentStatus::Investigating,
                'assigned_to' => $aisha->id,
                'reported_by' => $aisha->id,
            ]);
        }

        if ($diani && ! $diani->incidents()->exists()) {
            ProviderIncident::query()->create([
                'travel_provider_id' => $diani->id,
                'occurred_on' => today()->subDays(30),
                'type' => IncidentType::CustomerComplaint,
                'severity' => IncidentSeverity::Low,
                'description' => 'Snorkelling gear was the wrong sizes for children.',
                'resolution' => 'Provider bought child sizes and refunded the family KES 2,000.',
                'status' => IncidentStatus::Resolved,
                'resolved_at' => now()->subDays(25),
                'reported_by' => $kevin->id,
            ]);
        }
    }

    private function salesperson(string $email, string $name): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password']);

        if (! $user->hasRole(Role::TravelSalesperson->value)) {
            $user->assignRole(Role::TravelSalesperson->value);
        }

        return $user;
    }
}
