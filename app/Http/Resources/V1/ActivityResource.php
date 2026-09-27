<?php

namespace App\Http\Resources\V1;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A logged touchpoint with a lead (call, meeting, visit…).
 *
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'happened_at' => $this->happened_at->toIso8601String(),
            'notes' => $this->notes,
            'next_action' => $this->next_action,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
