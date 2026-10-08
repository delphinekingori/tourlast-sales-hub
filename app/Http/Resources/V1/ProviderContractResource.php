<?php

namespace App\Http\Resources\V1;

use App\Models\ProviderContract;
use App\Support\Travel\ContractTerms;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A provider contract. `status` is the effective status (Expiring soon and
 * Expired are worked out from the end date). Commission figures only for
 * people with "view travel financials" and the salesperson who owns the
 * provider; document files are listed, never included.
 *
 * @mixin ProviderContract
 */
class ProviderContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $provider = $this->provider;
        $seesCommission = $provider && ContractTerms::seesCommission($request->user(), $provider);
        $status = $this->effectiveStatus();

        return [
            'id' => $this->id,
            'contract_number' => $this->contract_number,
            'provider_id' => $this->travel_provider_id,
            'provider_name' => $provider?->name,
            'contract_type' => $this->contract_type,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'days_until_expiry' => $this->daysUntilExpiry(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'stored_status' => $this->status->value,
            'commission_model' => $this->when($seesCommission, $this->commission_model?->value),
            'commission_model_label' => $this->when($seesCommission, $this->commission_model?->label()),
            'commission_rate' => $this->when($seesCommission, $this->commission_rate),
            'fixed_commission' => $this->when($seesCommission, $this->fixed_commission),
            'currency' => $this->currency,
            'payment_terms' => $this->payment_terms,
            'settlement_terms' => $this->settlement_terms,
            'cancellation_terms' => $this->cancellation_terms,
            'refund_terms' => $this->refund_terms,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'documents' => $this->when($seesCommission && $this->relationLoaded('documents'), fn () => $this->documents->map(fn ($document) => [
                'id' => $document->id,
                'type' => $document->type,
                'type_label' => $document->typeLabel(),
                'name' => $document->original_name,
                'size' => $document->size,
                'current' => $document->isCurrent(),
            ])->all()),
        ];
    }
}
