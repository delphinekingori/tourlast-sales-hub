<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\ReferralTarget;
use Database\Factories\ReferralCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
     * Codes whose owner has not been fired. A fired salesperson's code stops
     * crediting new signups; reinstating the owner restores it.
     *
     * @param  Builder<ReferralCode>  $query
     */
    public function scopeCreditable(Builder $query): void
    {
        $query->whereHas('user', fn (Builder $user) => $user->where(fn (Builder $status) => $status->whereNull('account_status')->orWhere('account_status', '!=', AccountStatus::Terminated->value)));
    }

    public function ownerIsFired(): bool
    {
        return $this->user?->accountStatus() === AccountStatus::Terminated;
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
     * The Stays link has no suffix, so links already shared keep working.
     */
    public function shareUrl(ReferralTarget $target = ReferralTarget::Stays): string
    {
        return $target === ReferralTarget::Stays
            ? route('referral.redirect', $this->code)
            : route('referral.redirect', [$this->code, $target->value]);
    }

    /**
     * The tourlast.com registration page the tracked link forwards to.
     */
    public function destinationUrl(ReferralTarget $target = ReferralTarget::Stays): string
    {
        return $target->registrationUrl().'?'.http_build_query(['ref' => $this->code]);
    }
}
