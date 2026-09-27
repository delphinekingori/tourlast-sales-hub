<?php

namespace App\Actions;

use App\Models\Onboarding;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignOnboarding
{
    /**
     * Credit an onboarding to a salesperson by hand, with a reason on record.
     * Later syncs keep this assignment.
     */
    public function handle(Onboarding $onboarding, User $salesperson, User $admin, string $reason): Onboarding
    {
        return DB::transaction(function () use ($onboarding, $salesperson, $admin, $reason): Onboarding {
            $onboarding->attributionChanges()->create([
                'from_user_id' => $onboarding->user_id,
                'to_user_id' => $salesperson->id,
                'changed_by' => $admin->id,
                'reason' => trim($reason),
            ]);

            $onboarding->update([
                'user_id' => $salesperson->id,
                'referral_code_id' => $salesperson->referralCode?->id,
                'attribution' => 'manual',
            ]);

            return $onboarding;
        });
    }
}
