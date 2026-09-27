<?php

namespace App\Http\Resources\V1;

use App\Models\PointEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the append-only points ledger.
 *
 * @mixin PointEntry
 */
class PointEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'partner_account_id' => $this->partner_account_id,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'points' => $this->points,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'earned_on' => $this->earned_on?->toDateString(),
            'month' => $this->month?->format('Y-m'),
            'bonus_week' => $this->bonus_week,
            'reason' => $this->reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
