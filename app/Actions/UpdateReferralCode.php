<?php

namespace App\Actions;

use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Change one salesperson's referral code (Permission::ManageRefCodes).
 *
 * The Hub is the source of truth for codes: source apps pull the list from
 * `GET /integrations/tourlast/ref-codes` and stamp it on the properties they
 * send, so a code is normalised to upper case, kept unique, and only people
 * whose role earns referrals can have one. Historical onboardings keep the
 * code they were credited with.
 */
class UpdateReferralCode
{
    public function handle(User $user, string $code): ReferralCode
    {
        $code = Str::upper(trim($code));

        if (! $user->role()?->earnsReferrals()) {
            throw ValidationException::withMessages([
                'edit.ref_code' => "{$user->name} does not have a referral code.",
            ]);
        }

        $existing = $user->referralCode()->first();

        if (ReferralCode::query()->where('code', $code)->where('user_id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'edit.ref_code' => 'That referral code is already used by someone else.',
            ]);
        }

        if ($existing) {
            $existing->update(['code' => $code]);

            return $existing;
        }

        return $user->referralCodes()->create([
            'code' => $code,
            'is_active' => true,
        ]);
    }
}
