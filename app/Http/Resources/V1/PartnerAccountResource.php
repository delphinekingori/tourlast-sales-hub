<?php

namespace App\Http\Resources\V1;

use App\Incentives\ChecklistItem;
use App\Models\PartnerAccount;
use App\Models\PointEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Partner Account (one legal business) with its points and qualification.
 * The checklist, snapshots, properties and points appear when loaded (detail view).
 *
 * @mixin PartnerAccount
 */
class PartnerAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'category' => $this->category,
            'category_label' => $this->categoryLabel(),
            'salesperson' => new UserSummaryResource($this->whenLoaded('user')),
            'activation_date' => $this->activation_date?->toIso8601String(),
            'activation_inventory' => $this->activation_inventory,
            'current_inventory' => $this->whenLoaded('inventorySnapshots', fn () => $this->currentInventory()),
            'inventory_basis' => $this->inventory_basis,
            'inventory_basis_label' => $this->basisLabel(),
            'inventory_note' => $this->inventory_note,
            'qualification_status' => $this->qualification_status,
            'verified' => $this->isVerified(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $this->whenLoaded('verifier', fn () => $this->verifier ? ['id' => $this->verifier->id, 'name' => $this->verifier->name] : null),
            'review' => [
                'status' => $this->reviewStatus(),
                'ends_at' => $this->reviewEndsAt()?->toIso8601String(),
                'failed_at' => $this->review_failed_at?->toIso8601String(),
                'failed_reason' => $this->review_failed_reason,
            ],
            'expansion_days_left' => $this->expansionDaysLeft(),
            'live_points' => $this->whenLoaded('pointEntries', fn () => $this->livePoints()),
            'checklist_progress' => $this->whenLoaded('checklistItems', fn () => $this->checklistProgress()),
            'checklist' => $this->whenLoaded('checklistItems', function () {
                $items = $this->checklistItems->keyBy(fn ($row) => $row->item->value);

                return collect(ChecklistItem::cases())->map(fn (ChecklistItem $item) => [
                    'item' => $item->value,
                    'label' => $item->label(),
                    'automatic' => $item->isAutomatic(),
                    'wants_evidence' => $item->wantsEvidence(),
                    'completed_at' => $items->get($item->value)?->completed_at?->toIso8601String(),
                    'has_evidence' => (bool) $items->get($item->value)?->evidence_path,
                ])->values();
            }),
            'properties' => $this->whenLoaded('onboardings', fn () => $this->onboardings->map(fn ($onboarding) => [
                'onboarding_id' => $onboarding->id,
                'property_name' => $onboarding->property_name,
                'status' => $onboarding->status->value,
            ])->values()),
            'inventory_snapshots' => $this->whenLoaded('inventorySnapshots', fn () => $this->inventorySnapshots->map(fn ($snapshot) => [
                'id' => $snapshot->id,
                'count' => $snapshot->count,
                'live_on' => $snapshot->live_on?->toDateString(),
                'source' => $snapshot->source,
                'note' => $snapshot->note,
                'verified_at' => $snapshot->verified_at?->toIso8601String(),
            ])->values()),
            'points' => $this->whenLoaded('pointEntries', fn () => PointEntryResource::collection(
                $this->pointEntries->reject(fn (PointEntry $entry): bool => $entry->isReplaced())->values(),
            )),
        ];
    }
}
