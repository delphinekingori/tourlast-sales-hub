<?php

namespace App\Http\Resources\V1;

use App\Models\TravelProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tour, safari, experience or transport provider.
 *
 * @mixin TravelProvider
 */
class TravelProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider_type' => $this->provider_type?->value,
            'provider_type_label' => $this->provider_type?->label(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'business_name' => $this->business_name,
            'trading_name' => $this->trading_name,
            'registration_number' => $this->registration_number,
            'kra_pin' => $this->kra_pin,
            'country' => $this->country,
            'region' => $this->region,
            'city' => $this->city,
            'address' => $this->address,
            'website' => $this->website,
            'email' => $this->email,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'primary_contact' => ['name' => $this->primary_contact_name, 'phone' => $this->primary_contact_phone, 'email' => $this->primary_contact_email],
            'decision_maker' => ['name' => $this->decision_maker_name, 'phone' => $this->decision_maker_phone],
            'description' => $this->description,
            'owner' => new UserSummaryResource($this->whenLoaded('owner')),
            'property_engagement_id' => $this->property_engagement_id,
            'partner_account_id' => $this->partner_account_id,
            'packages_count' => $this->whenCounted('packages'),
            'contracts' => ProviderContractResource::collection($this->whenLoaded('contracts')),
            'archived' => $this->archived_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
