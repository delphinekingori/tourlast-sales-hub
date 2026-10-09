<?php

namespace App\Http\Resources\V1;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Support\Travel\TravelAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tour or experience package. `status` is the publication lifecycle; the
 * approved content customers see is `live_version`, a newer draft or pending
 * change is `working_version`. Cost, net price, commission and margin only
 * for "view travel financials" or the package owner.
 *
 * @mixin Package
 */
class PackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $financials = TravelAccess::seesFinancials($viewer) || $this->owner_id === $viewer->id;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'name' => $this->name,
            'package_type' => $this->package_type,
            'package_type_label' => $this->typeLabel(),
            'destination' => $this->destination,
            'country' => $this->country,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'approval_status' => $this->workingVersion?->status->value ?? $this->liveVersion?->status->value,
            'approval_status_label' => $this->workingVersion?->status->label() ?? $this->liveVersion?->status->label(),
            'approval_required' => $this->working_version_id !== null && $this->live_version_id !== null,
            'provider' => $this->whenLoaded('provider', fn () => ['id' => $this->provider->id, 'name' => $this->provider->name]),
            'contract_id' => $this->provider_contract_id,
            'owner' => new UserSummaryResource($this->whenLoaded('owner')),
            'created_by' => new UserSummaryResource($this->whenLoaded('creator')),
            'guide_required' => $this->guide_required,
            'published_at' => $this->published_at?->toIso8601String(),
            'published_channel' => $this->published_channel,
            'published_url' => $this->published_url,
            'live_version' => $this->whenLoaded('liveVersion', fn () => $this->version($this->liveVersion, $financials)),
            'working_version' => $this->whenLoaded('workingVersion', fn () => $this->version($this->workingVersion, $financials)),
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($asset) => [
                'id' => $asset->id,
                'title' => $asset->title,
                'alt_text' => $asset->alt_text,
                'url' => $asset->url(),
                'is_primary' => (bool) $asset->pivot->is_primary,
                'position' => (int) $asset->pivot->position,
            ])->all()),
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvals->map(fn ($approval) => [
                'version_id' => $approval->package_version_id,
                'level' => $approval->level->value,
                'level_label' => $approval->level->label(),
                'decision' => $approval->decision->value,
                'decision_label' => $approval->decision->label(),
                'reason' => $approval->reason,
                'by' => $approval->user?->name,
                'decided_at' => $approval->decided_at?->toIso8601String(),
            ])->all()),
            'archived' => $this->archived_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function version(?PackageVersion $version, bool $financials): ?array
    {
        if (! $version) {
            return null;
        }

        $data = [
            'id' => $version->id,
            'version' => $version->label(),
            'status' => $version->status->value,
            'status_label' => $version->status->label(),
            ...$version->only([
                'name', 'short_description', 'description', 'destination', 'country', 'region', 'start_location', 'end_location',
                'duration_label', 'days', 'nights', 'difficulty', 'min_travelers', 'max_travelers', 'default_capacity', 'min_age', 'age_notes',
                'overview', 'highlights', 'inclusions', 'exclusions', 'requirements', 'what_to_bring', 'terms', 'cancellation_policy',
                'refund_policy', 'meeting_point', 'pickup_info', 'dropoff_info', 'currency', 'adult_price', 'child_price', 'infant_price',
                'group_price', 'group_min_size', 'single_supplement', 'discount_amount', 'material_changes',
            ]),
            'submitted_at' => $version->submitted_at?->toIso8601String(),
            'approved_at' => $version->approved_at?->toIso8601String(),
            'itinerary' => $version->relationLoaded('itineraryDays') ? $version->itineraryDays->map(fn ($day) => $day->only([
                'day_number', 'title', 'description', 'activities', 'meals', 'accommodation', 'transport', 'notes',
            ]))->all() : null,
        ];

        if ($financials) {
            $data += [...$version->only(PackageVersion::FinancialFields), 'margin' => $version->margin()];
        }

        return $data;
    }
}
