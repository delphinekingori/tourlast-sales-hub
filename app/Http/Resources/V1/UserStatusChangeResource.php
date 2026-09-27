<?php

namespace App\Http\Resources\V1;

use App\Models\UserStatusChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One suspension, termination or reinstatement.
 *
 * @mixin UserStatusChange
 */
class UserStatusChangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->from_status->value,
            'to_status' => $this->to_status->value,
            'reason' => $this->reason,
            'reason_label' => $this->reasonLabel(),
            'notes' => $this->notes,
            'suspended_until' => $this->suspended_until?->toDateString(),
            'changed_by' => $this->changer ? ['id' => $this->changer->id, 'name' => $this->changer->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
