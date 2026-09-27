<?php

namespace App\Http\Resources\V1;

use App\Models\PropertyEngagement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A registry record as a row in the registry list.
 *
 * @mixin PropertyEngagement
 */
class PropertyEngagementSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'property_type' => $this->property_type,
            'property_type_label' => $this->propertyTypeLabel(),
            'location' => $this->locationLabel(),
            'city' => $this->city,
            'region' => $this->region,
            'country' => $this->country,
            'stage' => $this->stage->value,
            'stage_label' => $this->stage->label(),
            'stage_order' => $this->stage->order(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'sales_rep' => new UserSummaryResource($this->whenLoaded('salesRep')),
            'primary_contact' => $this->whenLoaded('primaryContact', fn () => $this->primaryContact ? [
                'name' => $this->primaryContact->name,
                'title' => $this->primaryContact->title,
                'phone' => $this->primaryContact->phone,
                'email' => $this->primaryContact->email,
            ] : null),
            'first_engaged_on' => $this->first_engaged_on?->toDateString(),
            'last_engaged_on' => $this->last_engaged_on?->toDateString(),
            'next_action' => $this->next_action,
            'next_action_on' => $this->next_action_on?->toDateString(),
            'archived' => $this->trashed(),
        ];
    }
}
