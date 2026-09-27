<?php

namespace App\Http\Resources\V1;

use App\Models\LeadTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ownership handover of a lead.
 *
 * @mixin LeadTransfer
 */
class LeadTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from' => new UserSummaryResource($this->whenLoaded('fromUser')),
            'to' => new UserSummaryResource($this->whenLoaded('toUser')),
            'transferred_by' => new UserSummaryResource($this->whenLoaded('transferrer')),
            'reason' => $this->reason,
            'reason_label' => $this->reasonLabel(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
