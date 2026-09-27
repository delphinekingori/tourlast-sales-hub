<?php

namespace App\Http\Resources\V1;

use App\Models\PropertyEngagement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A registry record's full profile: details, contacts, representatives,
 * merged timeline, upcoming schedule, links and outcome.
 *
 * @mixin PropertyEngagement
 */
class PropertyEngagementResource extends JsonResource
{
    /** @var Collection<int, JsonResource>|null */
    private ?Collection $timeline = null;

    /** @var Collection<int, mixed>|null */
    private ?Collection $upcoming = null;

    /**
     * @param  Collection<int, JsonResource>  $timeline
     * @param  Collection<int, mixed>  $upcoming
     */
    public function withProfile(Collection $timeline, Collection $upcoming): static
    {
        $this->timeline = $timeline;
        $this->upcoming = $upcoming;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'property_type' => $this->property_type,
            'property_type_label' => $this->propertyTypeLabel(),
            'star_rating' => $this->star_rating,
            'star_rating_label' => $this->starRatingLabel(),
            'tourlast_property_id' => $this->tourlast_property_id,
            'website' => $this->website,
            'trading_name' => $this->trading_name,
            'registration_name' => $this->registration_name,
            'registration_number' => $this->registration_number,
            'kra_pin' => $this->kra_pin,
            'rooms' => $this->rooms,
            'capacity' => $this->capacity,
            'location' => [
                'label' => $this->locationLabel(),
                'country' => $this->country,
                'region' => $this->region,
                'city' => $this->city,
                'area' => $this->area,
                'address' => $this->address,
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            ],
            'stage' => $this->stage->value,
            'stage_label' => $this->stage->label(),
            'stage_order' => $this->stage->order(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => $this->source?->value,
            'source_label' => $this->source?->label(),
            'summary' => $this->summary,
            'next_action' => $this->next_action,
            'next_action_on' => $this->next_action_on?->toDateString(),
            'first_engaged_on' => $this->first_engaged_on?->toDateString(),
            'last_engaged_on' => $this->last_engaged_on?->toDateString(),
            'outcome' => [
                'objection' => $this->objection?->value,
                'objection_label' => $this->objection?->label(),
                'competitor' => $this->competitor,
                'notes' => $this->outcome_notes,
                'closed_at' => $this->closed_at?->toIso8601String(),
                'reengage_on' => $this->reengage_on?->toDateString(),
            ],
            'sales_rep' => new UserSummaryResource($this->whenLoaded('salesRep')),
            'contacts' => EngagementContactResource::collection($this->whenLoaded('contacts')),
            'reps' => EngagementRepResource::collection($this->whenLoaded('reps')),
            'timeline' => $this->when($this->timeline !== null, fn () => $this->timeline),
            'upcoming' => $this->when($this->upcoming !== null, fn () => ScheduleItemResource::collection($this->upcoming)),
            'leads' => $this->whenLoaded('leads', fn () => $this->leads->map(fn ($lead) => [
                'id' => $lead->id,
                'business_name' => $lead->business_name,
                'status' => $lead->status->value,
                'status_label' => $lead->status->label(),
                'owner' => $lead->user ? ['id' => $lead->user->id, 'name' => $lead->user->name] : null,
                'created_at' => $lead->created_at?->toIso8601String(),
            ])->values()),
            'onboardings' => $this->whenLoaded('onboardings', fn () => $this->onboardings->map(fn ($onboarding) => [
                'id' => $onboarding->id,
                'tourlast_property_id' => $onboarding->tourlast_property_id,
                'property_name' => $onboarding->property_name,
                'status' => $onboarding->status->value,
                'status_label' => $onboarding->status->label(),
                'referred_by' => $onboarding->user ? ['id' => $onboarding->user->id, 'name' => $onboarding->user->name] : null,
                'submitted_at' => $onboarding->submitted_at?->toIso8601String(),
                'active_at' => $onboarding->active_at?->toIso8601String(),
            ])->values()),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
            'updated_by' => $this->whenLoaded('editor', fn () => $this->editor ? ['id' => $this->editor->id, 'name' => $this->editor->name] : null),
            'archived' => $this->trashed(),
            'can' => [
                'update' => (bool) $user?->can('update', $this->resource),
                'archive' => (bool) $user?->can('delete', $this->resource) && ! $this->trashed(),
                'restore' => (bool) $user?->can('restore', $this->resource) && $this->trashed(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
