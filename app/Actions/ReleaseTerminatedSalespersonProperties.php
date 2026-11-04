<?php

namespace App\Actions;

use App\Models\Onboarding;
use App\Models\PartnerAccount;
use App\Models\PointEntry;
use App\Models\User;
use App\Support\Alerts;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * When a salesperson is fired, properties that have not earned them credit yet
 * become unattributed until an admin assigns them. Properties whose account
 * already earned them approved points stay on their account, with the points
 * and pay records. Properties that arrive later are always unattributed.
 * Management is alerted with the number of properties released.
 */
class ReleaseTerminatedSalespersonProperties
{
    public function handle(User $user, User $by): int
    {
        $released = DB::transaction(function () use ($user): int {
            $credited = PointEntry::query()->where('user_id', $user->id)->where('status', 'approved')->whereNotNull('partner_account_id')->pluck('partner_account_id')->unique();

            PartnerAccount::query()->where('user_id', $user->id)->whereNotIn('id', $credited)->update(['user_id' => null]);

            return Onboarding::query()
                ->where('user_id', $user->id)
                ->where(fn ($query) => $query->whereNull('partner_account_id')->orWhereNotIn('partner_account_id', $credited))
                ->get()
                ->each(function (Onboarding $onboarding) use ($user): void {
                    $onboarding->forceFill(['user_id' => null, 'referral_code_id' => null, 'attribution' => 'referral'])->saveQuietly();

                    Audit::record(
                        $onboarding,
                        'onboarding.released_unattributed',
                        "{$onboarding->property_name} is now unattributed: {$user->name} was fired",
                        ['user_id' => [$user->id, null]],
                    );
                })
                ->count();
        });

        if ($released > 0) {
            Alerts::send(
                'account_status',
                'Properties need a salesperson',
                "{$released} ".($released === 1 ? 'property' : 'properties')." of {$user->name} (fired by {$by->name}) are now unattributed. Assign them to a salesperson.",
                route('people.show', $user),
            );
        }

        return $released;
    }
}
