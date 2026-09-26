<?php

namespace App\Actions;

use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Support\Str;

class IssueReferralCode
{
    /**
     * Give the user a permanent referral code such as TL-JOHN-2847.
     *
     * Returns the existing active code when the user already has one, and null
     * when the user's role does not earn referrals.
     */
    public function handle(User $user): ?ReferralCode
    {
        if (! $user->role()?->earnsReferrals()) {
            return null;
        }

        if ($existing = $user->referralCode()->first()) {
            return $existing;
        }

        return $user->referralCodes()->create([
            'code' => $this->uniqueCodeFor($user),
            'is_active' => true,
        ]);
    }

    private function uniqueCodeFor(User $user): string
    {
        $name = Str::of(Str::ascii($user->firstName()))->upper()->replaceMatches('/[^A-Z]/', '')->limit(10, '');
        $name = $name->isEmpty() ? 'SALES' : (string) $name;

        do {
            $code = sprintf('%s-%s-%04d', config('hub.referral_prefix'), $name, random_int(1000, 9999));
        } while (ReferralCode::query()->where('code', $code)->exists());

        return $code;
    }
}
