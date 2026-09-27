<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * A Sales Hub API token: owned by a user, limited to scopes, optionally
 * expiring, with a record of who issued it.
 */
class PersonalAccessToken extends SanctumToken
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'issued_by'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values((array) $this->abilities);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
