<?php

namespace App\Http\Resources\V1;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A call or meeting logged on a lead linked to a registry property, read into
 * the property's timeline (it is not copied into the registry).
 *
 * @mixin Activity
 */
class EngagementLeadActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kind' => 'lead_activity',
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'is_interaction' => true,
            'happened_at' => $this->happened_at->toIso8601String(),
            'sales_rep' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
            'lead' => $this->lead ? ['id' => $this->lead->id, 'business_name' => $this->lead->business_name] : null,
            'notes' => $this->notes,
            'next_action' => $this->next_action,
            'via_lead' => true,
        ];
    }
}
