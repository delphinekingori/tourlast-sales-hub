<?php

namespace App\Actions\Travel\Providers;

use App\Enums\Travel\ContractStatus;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\ContractTerms;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits a provider contract. New contracts start as drafts. The
 * provider's owner may edit a contract until it is sent for approval; after
 * that only Travel managers may change it. Every change is audited.
 */
class SaveProviderContract
{
    /**
     * @param  array<string, mixed>  $data  Validated contract fields (no status).
     */
    public function handle(array $data, User $actor, TravelProvider $provider, ?ProviderContract $contract = null): ProviderContract
    {
        abort_unless(ContractTerms::canEdit($actor, $provider, $contract), 403);

        // Commission figures are only changed by people allowed to see them.
        if (! ContractTerms::seesCommission($actor, $provider)) {
            unset($data['commission_rate'], $data['fixed_commission']);
        }

        unset($data['status'], $data['travel_provider_id']);

        return DB::transaction(function () use ($data, $actor, $provider, $contract): ProviderContract {
            if (! $contract) {
                $contract = ProviderContract::query()->create([
                    ...$data,
                    'contract_number' => ($data['contract_number'] ?? null) ?: ProviderContract::nextNumber(),
                    'travel_provider_id' => $provider->id,
                    'status' => ContractStatus::Draft,
                    'created_by' => $actor->id,
                ]);
                Audit::record($contract, 'contract.created', 'Created contract '.$contract->contract_number.' with '.$provider->name);

                return $contract;
            }

            $before = $contract->only(array_keys($data));
            $contract->fill($data);

            // A new end date restarts the expiry alerts.
            if ($contract->isDirty('ends_on')) {
                $contract->last_expiry_alert_days = null;
            }

            $contract->save();
            $changes = Audit::diff($before, $contract->only(array_keys($data)));

            if ($changes !== []) {
                Audit::record($contract, 'contract.changed', 'Changed contract '.$contract->contract_number, $changes);
            }

            return $contract;
        });
    }
}
