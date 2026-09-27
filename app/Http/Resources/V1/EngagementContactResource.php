<?php

namespace App\Http\Resources\V1;

use App\Models\PropertyEngagementContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person at a registry property.
 *
 * @mixin PropertyEngagementContact
 */
class EngagementContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'email' => $this->email,
            'is_primary' => $this->is_primary,
            'is_decision_maker' => $this->is_decision_maker,
        ];
    }
}
