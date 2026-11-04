<?php

namespace App\Actions;

use App\Models\Onboarding;
use App\Models\PartnerAccount;
use App\Models\PayoutStatement;
use App\Models\PointEntry;
use App\Models\User;
use App\Support\Alerts;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * When a salesperson is fired, properties that have not earned them paid
 * credit yet become unattributed until an admin assigns them. Properties whose
 * account has approved points in a month whose payout is marked paid stay on
 * their account, with the points and pay records. Properties that arrive later
 * are always unattributed.
 * Management is alerted with the number of properties released.
 */
class ReleaseTerminatedSalespersonProperties
{
    public function handle(User $user, User $by): int
    {
        $released = DB::transaction(function () use ($user): int {
            $paidMonths = PayoutStatement::query()->where('user_id', $user->id)->where('status', 'paid')->get()
                ->map(fn (PayoutStatement $statement): string => $statement->month->format('Y-m'))
                ->all();

            $credited = PointEntry::query()->where('user_id', $user->id)->where('status', 'approved')->whereNotNull('partner_account_id')->get()
                ->filter(fn (PointEntry $entry): bool => in_array($entry->month->format('Y-m'), $paidMonths, true))
                ->pluck('partner_account_id')
                ->unique()
                ->values();

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
                route('onboardings.unattributed'),
            );
        }

        return $released;
    }
}
