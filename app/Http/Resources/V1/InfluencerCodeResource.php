<?php

namespace App\Http\Resources\V1;

use App\Models\InfluencerCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An influencer referral code with its commission terms and results (from
 * InfluencerCodeFilters::query(), which adds the stats).
 *
 * @mixin InfluencerCode
 */
class InfluencerCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $used = (int) ($this->bookings_used ?? 0);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'influencer' => $this->whenLoaded('influencer', fn () => [
                'id' => $this->influencer->id,
                'name' => $this->influencer->name,
                'platform' => $this->influencer->platform,
                'handle' => $this->influencer->handle,
                'platforms' => $this->influencer->platforms->map(fn ($platform) => [
                    'platform' => $platform->platform,
                    'label' => $platform->label(),
                    'handle' => $platform->handle,
                    'url' => $platform->url,
                ])->values()->all(),
                'owner' => $this->influencer->owner?->name,
            ]),
            'created_by' => $this->creator?->name,
            'commission_type' => $this->commission_type->value,
            'commission_value' => $this->commission_value,
            'terms' => $this->termsLabel(),
            'applies_to' => $this->applies_to->value,
            'applies_to_label' => $this->applies_to->label(),
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'max_bookings' => $this->max_bookings,
            'bookings_used' => $used,
            'bookings_remaining' => $this->max_bookings !== null ? max(0, $this->max_bookings - $used) : null,
            'revenue_generated' => round((float) ($this->revenue_generated ?? 0), 2),
            'commission_pending' => round((float) ($this->commission_pending ?? 0), 2),
            'commission_payable' => round((float) ($this->commission_payable ?? 0), 2),
            'commission_paid' => round((float) ($this->commission_paid ?? 0), 2),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
        ];
    }
}
