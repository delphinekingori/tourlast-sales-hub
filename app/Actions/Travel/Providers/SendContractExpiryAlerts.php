<?php

namespace App\Actions\Travel\Providers;

use App\Enums\Travel\ContractStatus;
use App\Models\ProviderContract;
use App\Support\Alerts;
use App\Support\Audit;

/**
 * Warns Travel managers and the provider's owner when an active contract is
 * 30, 14 and 7 days from its end (config travel.contract_alert_days), and
 * once when it has just expired. Each warning goes out once: the last one
 * sent is kept on the contract (0 = the expiry notice).
 */
class SendContractExpiryAlerts
{
    /** Contracts that ended longer ago than this are not announced again. */
    private const ExpiredLookbackDays = 7;

    /**
     * @return int Alerts sent.
     */
    public function handle(): int
    {
        $thresholds = collect(config('travel.contract_alert_days', [30, 14, 7]))->map(fn ($days) => (int) $days)->sortDesc()->values();
        $horizon = today()->addDays((int) $thresholds->first());
        $sent = 0;

        ProviderContract::query()
            ->with('provider.owner')
            ->where('status', ContractStatus::Active)
            ->whereNotNull('ends_on')
            ->whereDate('ends_on', '<=', $horizon->toDateString())
            ->whereDate('ends_on', '>=', today()->subDays(self::ExpiredLookbackDays)->toDateString())
            ->each(function (ProviderContract $contract) use ($thresholds, &$sent): void {
                $days = $contract->daysUntilExpiry();
                $bucket = $days < 0 ? 0 : $thresholds->filter(fn (int $threshold) => $days <= $threshold)->min();

                if ($bucket === null || ($contract->last_expiry_alert_days !== null && $bucket >= $contract->last_expiry_alert_days)) {
                    return;
                }

                $provider = $contract->provider;
                $title = $bucket === 0
                    ? "Contract with {$provider->name} has expired"
                    : "Contract with {$provider->name} expires in {$days} ".($days === 1 ? 'day' : 'days');
                $body = $bucket === 0
                    ? "Contract {$contract->contract_number} ended on {$contract->ends_on->format('j M Y')}. Its packages cannot be published until a new contract is active."
                    : "Contract {$contract->contract_number} ends on {$contract->ends_on->format('j M Y')}. Renew it to keep its packages on sale.";

                Alerts::sendTravel('contract_expiring', $title, $body, route('travel.contracts.show', $contract->id), $provider->owner);

                $contract->forceFill(['last_expiry_alert_days' => $bucket])->save();
                Audit::record($contract, 'contract.expiry_alert', $title, userId: null);
                $sent++;
            });

        return $sent;
    }
}
