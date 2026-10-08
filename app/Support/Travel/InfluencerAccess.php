<?php

namespace App\Support\Travel;

use App\Models\Influencer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and change influencers, codes and commission.
 *
 * - Travel salespeople manage their own influencers and codes.
 * - Travel managers see and manage everyone's.
 * - Accounts see everything and mark commission paid, but create nothing.
 * - Sales Managers and HR have no access.
 */
class InfluencerAccess
{
    public static function canView(User $user): bool
    {
        return TravelAccess::works($user) || TravelAccess::handlesPayments($user);
    }

    public static function seesAll(User $user): bool
    {
        return TravelAccess::managesAll($user) || TravelAccess::handlesPayments($user);
    }

    /**
     * Create influencers (owned by the creator) and codes for them.
     */
    public static function canCreate(User $user): bool
    {
        return TravelAccess::works($user);
    }

    public static function canManage(User $user, Influencer $influencer): bool
    {
        return TravelAccess::canChange($user, $influencer->owner_id);
    }

    public static function canSee(User $user, Influencer $influencer): bool
    {
        return self::seesAll($user) || (TravelAccess::works($user) && $influencer->owner_id === $user->id);
    }

    public static function seesPayoutDetails(User $user, Influencer $influencer): bool
    {
        return self::seesAll($user) || $influencer->owner_id === $user->id;
    }

    public static function canMarkPaid(User $user): bool
    {
        return TravelAccess::managesAll($user) || TravelAccess::handlesPayments($user);
    }

    /**
     * Limit an Influencer query to what the user may see.
     *
     * @param  Builder<Influencer>  $query
     * @return Builder<Influencer>
     */
    public static function scopeInfluencers(Builder $query, User $user): Builder
    {
        return $query->when(! self::seesAll($user), fn (Builder $query) => $query->where('owner_id', $user->id));
    }

    public static function abortUnlessCanView(User $user): void
    {
        abort_unless(self::canView($user), 403);
    }
}
