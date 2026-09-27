<?php

namespace App\Http\Resources\V1;

use App\Models\PropertyEngagementEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of a registry record's own timeline (never edited or deleted).
 *
 * @mixin PropertyEngagementEvent
 */
class EngagementEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kind' => 'event',
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'is_interaction' => $this->type->isInteraction(),
            'happened_at' => $this->happened_at->toIso8601String(),
            'sales_rep' => $this->salesRep ? ['id' => $this->salesRep->id, 'name' => $this->salesRep->name] : null,
            'recorded_by' => $this->recorder ? ['id' => $this->recorder->id, 'name' => $this->recorder->name] : null,
            'transition' => $this->transition(),
            'summary' => $this->summary,
            'notes' => $this->notes,
            'changes' => $this->changes,
            'via_lead' => false,
            'recorded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
