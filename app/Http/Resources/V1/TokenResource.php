<?php

namespace App\Http\Resources\V1;

use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An API token's details (never the secret itself).
 *
 * @mixin PersonalAccessToken
 */
class TokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scopes' => $this->scopes(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'issued_by' => $this->whenLoaded('issuer', fn () => $this->issuer ? ['id' => $this->issuer->id, 'name' => $this->issuer->name] : null),
            'current' => $request->user()?->currentAccessToken()?->getKey() === $this->id,
        ];
    }
}
