<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person in the team directory (Users & Invites / People).
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->accountStatus();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role()?->value,
            'role_label' => $this->role()?->label(),
            'region' => $this->region,
            'job_title' => $this->job_title,
            'avatar_url' => $this->avatarUrl(),
            'account_status' => $status->value,
            'account_status_label' => $status->label(),
            'suspended_until' => $this->suspended_until?->toDateString(),
            'online' => $this->isOnline(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'referral_code' => $this->whenLoaded('referralCode', fn () => $this->referralCode?->code),
            'status_history' => UserStatusChangeResource::collection($this->whenLoaded('statusChanges')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
