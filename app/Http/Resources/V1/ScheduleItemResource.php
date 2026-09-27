<?php

namespace App\Http\Resources\V1;

use App\Models\FollowUp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A scheduled call, meeting, site visit or follow-up.
 *
 * @mixin FollowUp
 */
class ScheduleItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'title' => $this->task,
            'due_at' => $this->due_at->toIso8601String(),
            'has_time' => $this->has_time,
            'duration_minutes' => $this->duration_minutes,
            'time_label' => $this->timeLabel(),
            'contact_name' => $this->contact_name,
            'contact_role' => $this->contact_role,
            'location' => $this->location,
            'notes' => $this->notes,
            'is_meeting' => $this->isMeeting(),
            'is_overdue' => $this->isOverdue(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'outcome_activity_id' => $this->outcome_activity_id,
            'lead' => $this->whenLoaded('lead', fn () => [
                'id' => $this->lead->id,
                'business_name' => $this->lead->business_name,
                'property_engagement_id' => $this->lead->property_engagement_id,
            ]),
            'owner' => new UserSummaryResource($this->whenLoaded('user')),
        ];
    }
}
