<?php

namespace App\Models;

use Database\Factories\ReferralCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'code', 'is_active'])]
class ReferralCode extends Model
{
    /** @use HasFactory<ReferralCodeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ReferralClick, $this>
     */
    public function clicks(): HasMany
    {
        return $this->hasMany(ReferralClick::class);
    }

    /**
     * @return HasMany<Onboarding, $this>
     */
    public function onboardings(): HasMany
    {
        return $this->hasMany(Onboarding::class);
    }

    /**
     * The short link the salesperson shares. It is counted, then forwarded to tourlast.com.
     */
    public function shareUrl(): string
    {
        return route('referral.redirect', $this->code);
    }

    /**
     * The tourlast.com page the tracked link forwards to.
     */
    public function destinationUrl(): string
    {
        return config('hub.list_property_url').'?'.http_build_query(['ref' => $this->code]);
    }
}
