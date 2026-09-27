<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One "possible existing property" from App\Support\PropertyDuplicateCheck.
 * Returned by the duplicate check and in 409 responses when creating leads or
 * registry records.
 *
 * @property array<string, mixed> $resource
 */
class DuplicateMatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $match = $this->resource;

        return [
            'kind' => $match['kind'],
            'key' => $match['key'],
            'name' => $match['name'],
            'location' => $match['location'],
            'owner' => $match['owner'],
            'owner_is_you' => $match['ownerIsViewer'],
            'stage' => $match['stage'],
            'last_contacted' => $match['lastContacted']?->toIso8601String(),
            'reasons' => $match['reasons'],
            'active' => $match['active'],
            'archived' => $match['archived'],
            'engagement_id' => $match['engagementId'],
            'lead_id' => $match['ownerIsViewer'] || $request->user()?->can('view-team-performance') ? $match['leadId'] : null,
        ];
    }
}
