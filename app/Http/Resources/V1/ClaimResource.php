<?php

namespace App\Http\Resources\V1;

use App\Models\ExpenseClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An airtime claim, transport reimbursement or transport request (KES).
 *
 * @mixin ExpenseClaim
 */
class ClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'claimant' => new UserSummaryResource($this->whenLoaded('user')),
            'month' => $this->month?->format('Y-m'),
            'amount' => $this->amount,
            'approved_amount' => $this->approved_amount,
            'payable_amount' => $this->payableAmount(),
            'currency' => 'KES',
            'description' => $this->description,
            'travel_date' => $this->travel_date?->toDateString(),
            'ride_provider' => $this->ride_provider,
            'ride_provider_label' => $this->rideProviderLabel(),
            'trip_reference' => $this->trip_reference,
            'pickup' => $this->pickup,
            'dropoff' => $this->dropoff,
            'distance_km' => $this->distance_km,
            'partner_account_id' => $this->partner_account_id,
            'lead_id' => $this->lead_id,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'current_step' => $this->current_step,
            'steps' => $this->steps(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_reference' => $this->payment_reference,
            'created_at' => $this->created_at?->toIso8601String(),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'kind' => $attachment->kind,
                'kind_label' => $attachment->kindLabel(),
                'name' => $attachment->original_name,
                'mime' => $attachment->mime,
                'size' => $attachment->size,
                'download_url' => route('api.v1.claims.attachments.show', [$this->id, $attachment->id]),
            ])->values()),
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvals->map(fn ($approval) => [
                'step' => $approval->step,
                'step_label' => $approval->stepLabel(),
                'decision' => $approval->decision,
                'amount' => $approval->amount,
                'note' => $approval->note,
                'by' => $approval->relationLoaded('user') && $approval->user ? ['id' => $approval->user->id, 'name' => $approval->user->name] : null,
                'created_at' => $approval->created_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
