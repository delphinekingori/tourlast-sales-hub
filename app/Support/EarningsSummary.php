<?php

namespace App\Support;

use App\Incentives\Calculator;
use App\Incentives\Earnings;
use App\Incentives\MonthlyEarnings;
use App\Models\PayoutStatement;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A salesperson's pay for a month as plain data (what My Earnings shows),
 * for the API.
 */
class EarningsSummary
{
    public function __construct(private MonthlyEarnings $monthly, private Calculator $calculator) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, CarbonImmutable $month): array
    {
        $policy = $this->monthly->policyFor($month);
        $earnings = $this->monthly->for($user, $month);
        $withPending = $this->calculator->withProvisional($policy, $earnings);
        $statement = PayoutStatement::query()->where('user_id', $user->id)->whereDate('month', $month->toDateString())->first();

        return [
            'user_id' => $user->id,
            'month' => $month->format('Y-m'),
            'currency' => 'KES',
            'has_agreement' => $this->monthly->hasAgreement($user, $month),
            'approved' => $this->figures($earnings),
            'including_provisional' => $this->figures($withPending),
            'next_step' => $this->calculator->nextStep($policy, $earnings->points),
            'statement' => $statement ? [
                'id' => $statement->id,
                'status' => $statement->status,
                'total' => (float) $statement->total,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function figures(Earnings $earnings): array
    {
        return [
            'points' => $earnings->points,
            'provisional_points' => $earnings->provisionalPoints,
            'retainer' => $earnings->retainer,
            'retainer_compliant' => $earnings->retainerCompliant,
            'weekly_points' => $earnings->weeklyPoints,
            'weekly_bonuses' => $earnings->weeklyBonuses,
            'weekly_bonus_total' => $earnings->weeklyBonusTotal(),
            'monthly_bonus' => $earnings->monthlyBonus,
            'exceptional' => $earnings->exceptional,
            'airtime' => $earnings->airtime,
            'transport' => $earnings->transport,
            'adjustments' => $earnings->adjustments,
            'adjustment_lines' => $earnings->adjustmentLines,
            'incentives' => $earnings->incentives(),
            'total' => $earnings->total(),
        ];
    }
}
