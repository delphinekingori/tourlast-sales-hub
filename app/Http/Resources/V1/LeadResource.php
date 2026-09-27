<?php

namespace App\Http\Resources\V1;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A salesperson's lead. Relations are included when loaded (the detail
 * endpoint loads activities, open schedule, transfers, registry and onboarding).
 *
 * @mixin Lead
 */
class LeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'trading_name' => $this->trading_name,
            'property_type' => $this->property_type,
            'property_type_label' => $this->propertyTypeLabel(),
            'location' => $this->location,
            'contact_name' => $this->contact_name,
            'contact_role' => $this->contact_role,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'website' => $this->website,
            'registration_number' => $this->registration_number,
            'kra_pin' => $this->kra_pin,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_open' => $this->isOpen(),
            'last_contacted_at' => $this->last_contacted_at?->toIso8601String(),
            'lost' => $this->lost_at || $this->objection ? [
                'reason' => $this->lost_reason,
                'objection' => $this->objection?->value,
                'objection_label' => $this->objection?->label(),
                'competitor' => $this->competitor,
                'notes' => $this->lost_notes,
                'lost_at' => $this->lost_at?->toIso8601String(),
                'reengage_on' => $this->reengage_on?->toDateString(),
            ] : null,
            'owner' => new UserSummaryResource($this->whenLoaded('user')),
            'property_engagement_id' => $this->property_engagement_id,
            'property_engagement' => $this->whenLoaded('propertyEngagement', fn () => $this->propertyEngagement ? [
                'id' => $this->propertyEngagement->id,
                'name' => $this->propertyEngagement->name,
                'stage' => $this->propertyEngagement->stage->value,
                'status' => $this->propertyEngagement->status->value,
            ] : null),
            'onboarding' => $this->whenLoaded('onboarding', fn () => $this->onboarding ? [
                'id' => $this->onboarding->id,
                'property_name' => $this->onboarding->property_name,
                'status' => $this->onboarding->status->value,
                'status_label' => $this->onboarding->status->label(),
                'submitted_at' => $this->onboarding->submitted_at?->toIso8601String(),
                'credited_at' => $this->onboarding->credited_at?->toIso8601String(),
            ] : null),
            'next_follow_up' => new ScheduleItemResource($this->whenLoaded('nextFollowUp')),
            'open_schedule' => ScheduleItemResource::collection($this->whenLoaded('followUps', fn () => $this->followUps->whereNull('completed_at')->values())),
            'activities' => ActivityResource::collection($this->whenLoaded('activities')),
            'transfers' => LeadTransferResource::collection($this->whenLoaded('transfers')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
