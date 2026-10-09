<?php

namespace App\Support\Travel;

use App\Enums\Travel\CommissionModel;
use App\Enums\Travel\ContractStatus;
use App\Models\ProviderContract;
use App\Models\TravelProvider;

/**
 * Whether a provider and its contract allow a package to be published.
 * Used by the packages module before publishing (and in the readiness check).
 */
class ContractCheck
{
    /**
     * What is missing; an empty list means the package may be published.
     *
     * @return list<string>
     */
    public static function forPackage(?ProviderContract $contract, TravelProvider $provider): array
    {
        $missing = [];

        if (! $provider->isActive()) {
            $missing[] = 'Provider is active';
        }

        if (blank($provider->phone) && blank($provider->email) && blank($provider->primary_contact_phone) && blank($provider->primary_contact_email)) {
            $missing[] = 'Provider contact details';
        }

        if ($contract === null || $contract->travel_provider_id !== $provider->id) {
            $missing[] = 'Active provider contract';

            return $missing;
        }

        if ($contract->status !== ContractStatus::Active) {
            $missing[] = 'Active provider contract';
        } elseif ($contract->starts_on->gt(today())) {
            $missing[] = 'Contract has started';
        }

        if ($contract->ends_on !== null && $contract->ends_on->lt(today())) {
            $missing[] = 'Contract not expired';
        }

        if (blank($contract->cancellation_terms)) {
            $missing[] = 'Cancellation terms';
        }

        $ratesMissing = match ($contract->commission_model) {
            CommissionModel::Percentage => $contract->commission_rate === null,
            CommissionModel::Fixed => $contract->fixed_commission === null,
            CommissionModel::NetRate, null => false,
        };

        if ($ratesMissing) {
            $missing[] = 'Commission rates';
        }

        return $missing;
    }
}
