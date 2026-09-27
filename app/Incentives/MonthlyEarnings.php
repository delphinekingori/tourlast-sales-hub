<?php

namespace App\Incentives;

use App\Models\ExpenseClaim;
use App\Models\IncentiveAgreement;
use App\Models\IncentivePolicy;
use App\Models\PayoutStatement;
use App\Models\PointEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Works out a salesperson's incentive figures for a month from the ledger,
 * approved claims and any corrections to earlier paid months.
 */
class MonthlyEarnings
{
    public function __construct(private Calculator $calculator) {}

    public function policyFor(CarbonImmutable $month): Policy
    {
        return IncentivePolicy::for($month->startOfMonth())->policy();
    }

    public function hasAgreement(User $user, CarbonImmutable $month): bool
    {
        return IncentiveAgreement::query()->where('user_id', $user->id)->coveringMonth($month)->exists();
    }

    public function for(User $user, CarbonImmutable $month, ?bool $compliant = null, bool $withAdjustments = true): Earnings
    {
        $month = $month->startOfMonth();
        $policy = $this->policyFor($month);

        [$approved, $provisional] = $this->weeklyPoints($user, $month);

        if (! $this->hasAgreement($user, $month)) {
            return $this->calculator->calculate($policy, [], [], false);
        }

        $statement = PayoutStatement::query()->where('user_id', $user->id)->whereDate('month', $month->toDateString())->first();
        $compliant ??= $statement ? $statement->isCompliant() : true;

        return $this->calculator->calculate(
            $policy,
            $approved,
            $provisional,
            $compliant,
            $this->claimsTotal($user, $month, 'airtime'),
            $this->claimsTotal($user, $month, 'transport_reimbursement'),
            $withAdjustments ? $this->adjustmentLines($user, $month) : [],
        );
    }

    /**
     * Points per bonus week, whether or not the salesperson has an agreement.
     *
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    public function weeklyPoints(User $user, CarbonImmutable $month): array
    {
        $entries = PointEntry::query()
            ->where('user_id', $user->id)
            ->forMonth($month)
            ->counting()
            ->get(['points', 'bonus_week', 'status']);

        $approved = [];
        $provisional = [];

        foreach (range(1, 4) as $week) {
            $approved[$week] = (float) $entries->where('bonus_week', $week)->where('status', 'approved')->sum('points');
            $provisional[$week] = (float) $entries->where('bonus_week', $week)->where('status', 'provisional')->sum('points');
        }

        return [$approved, $provisional];
    }

    public function claimsTotal(User $user, CarbonImmutable $month, string $type): float
    {
        return (float) ExpenseClaim::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereDate('month', $month->toDateString())
            ->whereIn('status', ['approved', 'paid'])
            ->get()
            ->sum(fn (ExpenseClaim $claim): float => $claim->payableAmount());
    }

    /**
     * Differences between what earlier months paid and what they should have
     * paid now, after failed reviews (recoveries) or late approvals (top-ups).
     *
     * @return list<array{label: string, amount: float, statement_id: int}>
     */
    public function adjustmentLines(User $user, CarbonImmutable $month): array
    {
        $lines = [];

        $earlier = PayoutStatement::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['approved', 'paid'])
            ->whereDate('month', '<', $month->toDateString())
            ->orderBy('month')
            ->get();

        foreach ($earlier as $statement) {
            $now = $this->for($user, $statement->month, $statement->isCompliant(), false);
            $paid = $statement->retainer + $statement->weekly_bonus + $statement->monthly_bonus + $statement->exceptional;
            $difference = round($now->incentives() - $paid - $statement->recovered_amount, 2);

            if (abs($difference) >= 1) {
                $lines[] = [
                    'label' => ($difference < 0 ? 'Recovery for ' : 'Top-up for ').$statement->month->format('F Y')
                        .($difference < 0 ? ' (points cancelled after review)' : ' (points approved after payment)'),
                    'amount' => $difference,
                    'statement_id' => $statement->id,
                ];
            }
        }

        return $lines;
    }
}
