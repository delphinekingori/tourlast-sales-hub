<?php

namespace App\Http\Resources\V1;

use App\Models\ReferralCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One referral code as the source apps must know it.
 *
 * @mixin ReferralCode
 */
class ReferralCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'is_active' => (bool) $this->is_active,
            'user_id' => (int) $this->user_id,
            'user_name' => $this->user?->name,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
