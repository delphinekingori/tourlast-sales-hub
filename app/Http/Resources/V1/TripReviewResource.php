<?php

namespace App\Http\Resources\V1;

use App\Models\TripReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client's review of a completed trip.
 *
 * @mixin TripReview
 */
class TripReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->package_booking_id,
            'booking_reference' => $this->whenLoaded('booking', fn () => $this->booking?->reference),
            'package' => $this->whenLoaded('package', fn () => $this->package ? ['id' => $this->package->id, 'name' => $this->package->name] : null),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? ['id' => $this->provider->id, 'name' => $this->provider->name] : null),
            'client_name' => $this->whenLoaded('client', fn () => $this->client?->name),
            'rating' => $this->rating,
            'category_ratings' => [
                'service' => $this->rating_service,
                'guide' => $this->rating_guide,
                'value' => $this->rating_value,
                'safety' => $this->rating_safety,
            ],
            'comment' => $this->comment,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'is_low' => $this->isLow(),
            'is_hidden' => $this->is_hidden,
            'hidden_reason' => $this->when($this->is_hidden, $this->hidden_reason),
            'recorded_by' => new UserSummaryResource($this->whenLoaded('recorder')),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
        ];
    }
}
