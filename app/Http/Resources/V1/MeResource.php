<?php

namespace App\Http\Resources\V1;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in token owner, with what they are allowed to do.
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $token = $this->currentAccessToken();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'region' => $this->region,
            'job_title' => $this->job_title,
            'bio' => $this->bio,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'avatar_url' => $this->avatarUrl(),
            'role' => $this->role()?->value,
            'role_label' => $this->role()?->label(),
            'account_status' => $this->accountStatus()->value,
            'sells' => (bool) $this->role()?->earnsReferrals(),
            'referral_code' => $this->referralCode?->code,
            'referral_link' => $this->referralCode?->shareUrl(),
            'permissions' => collect(Permission::cases())->filter(fn (Permission $permission) => $this->resource->can($permission->value))->map(fn (Permission $permission) => $permission->value)->values(),
            'token' => $token && method_exists($token, 'scopes') ? [
                'name' => $token->name,
                'scopes' => $token->scopes(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
