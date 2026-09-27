<?php

namespace App\Http\Resources\V1;

use App\Enums\OnboardingStatus;
use App\Models\Onboarding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A provider signup read from tourlast.com, credited to a salesperson.
 *
 * @mixin Onboarding
 */
class OnboardingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tourlast_property_id' => $this->tourlast_property_id,
            'property_name' => $this->property_name,
            'property_type' => $this->property_type,
            'property_type_label' => $this->propertyTypeLabel(),
            'location' => $this->location,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_stalled' => $this->isStalled(),
            'ref_code' => $this->ref_code,
            'attribution' => $this->attribution,
            'salesperson' => new UserSummaryResource($this->whenLoaded('user')),
            'partner_account_id' => $this->partner_account_id,
            'property_engagement_id' => $this->property_engagement_id,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'active_at' => $this->active_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'credited_at' => $this->credited_at?->toIso8601String(),
            'first_booking_at' => $this->first_booking_at?->toIso8601String(),
            'progress' => $this->progress(),
            'status_history' => $this->whenLoaded('statusChanges', fn () => $this->statusChanges->map(fn ($change) => [
                'from' => $change->from_status?->value,
                'to' => $change->to_status->value,
                'to_label' => $change->to_status->label(),
                'source' => $change->source,
                'occurred_at' => $change->occurred_at?->toIso8601String(),
            ])->values()),
            'credit_changes' => $this->whenLoaded('attributionChanges', fn () => $this->attributionChanges->map(fn ($change) => [
                'from_user_id' => $change->from_user_id,
                'to_user_id' => $change->to_user_id,
                'changed_by' => $change->relationLoaded('changedBy') && $change->changedBy ? ['id' => $change->changedBy->id, 'name' => $change->changedBy->name] : null,
                'reason' => $change->reason,
                'created_at' => $change->created_at?->toIso8601String(),
            ])->values()),
        ];
    }

    /**
     * Referral → Application → Verification → Approval → Live, as shown in the Hub.
     *
     * @return array{steps: list<array{key: string, label: string, state: string}>, completed: int, rejected: bool}
     */
    private function progress(): array
    {
        $status = $this->status;
        $rejected = $status === OnboardingStatus::Rejected;
        $completed = match ($status) {
            OnboardingStatus::Submitted, OnboardingStatus::UnderReview => 2,
            OnboardingStatus::Approved => 4,
            OnboardingStatus::Active => 5,
            OnboardingStatus::Rejected => 3,
        };

        $steps = [];

        foreach (['referral' => 'Referral', 'application' => 'Application', 'verification' => 'Verification', 'approval' => 'Approval', 'live' => 'Live'] as $index => $label) {
            $position = count($steps);
            $steps[] = [
                'key' => $index,
                'label' => $rejected && $index === 'approval' ? 'Rejected' : $label,
                'state' => match (true) {
                    $position < $completed => 'done',
                    $position === $completed => $rejected ? 'rejected' : 'current',
                    default => 'upcoming',
                },
            ];
        }

        return ['steps' => $steps, 'completed' => $completed, 'rejected' => $rejected];
    }
}
