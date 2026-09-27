<?php

namespace App\Actions;

use App\Enums\ApiScope;
use App\Models\User;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;

class IssueApiToken
{
    /**
     * Create an API token for $user, limited to $scopes. The plain-text token
     * is only available on the returned object, never again.
     *
     * @param  list<string>  $scopes
     */
    public function handle(User $user, string $name, array $scopes, ?CarbonInterface $expiresAt = null, ?User $issuedBy = null): NewAccessToken
    {
        $unknown = array_diff($scopes, ApiScope::values());

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown scopes: '.implode(', ', $unknown));
        }

        if (! $user->is_active) {
            throw new InvalidArgumentException('Tokens can only be issued to active accounts.');
        }

        $token = $user->createToken(trim($name), array_values(array_unique($scopes)), $expiresAt);
        $token->accessToken->forceFill(['issued_by' => $issuedBy?->id ?? $user->id])->save();

        return $token;
    }
}
