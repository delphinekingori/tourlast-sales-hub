<?php

namespace App\Http\Resources\V1;

use App\Models\PropertyEngagementRep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One salesperson's period representing a registry property.
 *
 * @mixin PropertyEngagementRep
 */
class EngagementRepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'started_on' => $this->started_on?->toDateString(),
            'ended_on' => $this->ended_on?->toDateString(),
            'current' => $this->isCurrent(),
            'reason' => $this->reason,
            'reason_label' => $this->reasonLabel(),
            'notes' => $this->notes,
            'assigned_by' => $this->whenLoaded('assigner', fn () => $this->assigner ? ['id' => $this->assigner->id, 'name' => $this->assigner->name] : null),
        ];
    }
}
