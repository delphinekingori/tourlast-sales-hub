<?php

namespace App\Http\Resources\V1;

use App\Models\PayoutStatement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A monthly payout statement (KES).
 *
 * @mixin PayoutStatement
 */
class StatementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'salesperson' => new UserSummaryResource($this->whenLoaded('user')),
            'month' => $this->month->format('Y-m'),
            'status' => $this->status,
            'locked' => $this->isLocked(),
            'points' => $this->points,
            'weekly_points' => $this->weekly_points,
            'retainer' => (float) $this->retainer,
            'weekly_bonus' => (float) $this->weekly_bonus,
            'monthly_bonus' => (float) $this->monthly_bonus,
            'exceptional' => (float) $this->exceptional,
            'airtime' => $this->airtime,
            'transport' => $this->transport,
            'adjustments' => $this->adjustments,
            'adjustment_lines' => $this->adjustment_lines ?? [],
            'recovered_amount' => $this->recovered_amount,
            'total' => $this->total,
            'currency' => 'KES',
            'compliance' => collect(PayoutStatement::ComplianceItems)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'confirmed' => (bool) ($this->compliance[$key] ?? false),
            ])->values(),
            'compliant' => $this->isCompliant(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_by' => $this->paid_by,
            'payment_reference' => $this->payment_reference,
        ];
    }
}
