<?php

namespace App\Incentives;

/**
 * Turns points into pay exactly as the schedule states. Pure: no database access.
 */
class Calculator
{
    /**
     * @param  array<int, float>  $weeklyPoints  approved points per bonus week
     * @param  array<int, float>  $weeklyProvisional
     * @param  list<array{label: string, amount: float}>  $adjustmentLines
     */
    public function calculate(
        Policy $policy,
        array $weeklyPoints,
        array $weeklyProvisional = [],
        bool $retainerCompliant = true,
        float $airtime = 0,
        float $transport = 0,
        array $adjustmentLines = [],
    ): Earnings {
        $weeks = [];
        $provisional = [];

        foreach (range(1, 4) as $week) {
            $weeks[$week] = (float) ($weeklyPoints[$week] ?? 0);
            $provisional[$week] = (float) ($weeklyProvisional[$week] ?? 0);
        }

        $points = array_sum($weeks);

        return new Earnings(
            points: $points,
            provisionalPoints: array_sum($provisional),
            weeklyPoints: $weeks,
            weeklyProvisional: $provisional,
            retainer: $retainerCompliant ? $policy->retainer($points) : 0,
            retainerCompliant: $retainerCompliant,
            weeklyBonuses: array_map(fn (float $weekPoints): int => $policy->weeklyBonus($weekPoints), $weeks),
            monthlyBonus: $policy->monthlyBonus($points),
            exceptional: $policy->exceptional($points),
            airtime: min($airtime, $policy->airtimeCap()),
            transport: $transport,
            adjustments: array_sum(array_column($adjustmentLines, 'amount')),
            adjustmentLines: $adjustmentLines,
        );
    }

    /**
     * What the month would pay if the provisional points were approved too.
     */
    public function withProvisional(Policy $policy, Earnings $earnings): Earnings
    {
        $combined = [];

        foreach ($earnings->weeklyPoints as $week => $points) {
            $combined[$week] = $points + ($earnings->weeklyProvisional[$week] ?? 0);
        }

        return $this->calculate($policy, $combined, [], $earnings->retainerCompliant, $earnings->airtime, $earnings->transport, $earnings->adjustmentLines);
    }

    /**
     * The next points level where pay goes up, and by how much.
     *
     * @return array{points: float, needed: float, label: string, gain: int}|null
     */
    public function nextStep(Policy $policy, float $points): ?array
    {
        foreach ($policy->steps() as $step) {
            if ($step['points'] > $points) {
                $current = $policy->retainer($points) + $policy->monthlyBonus($points);
                $next = $policy->retainer($step['points']) + $policy->monthlyBonus($step['points']);

                return [
                    'points' => $step['points'],
                    'needed' => $step['points'] - $points,
                    'label' => $step['label'],
                    'gain' => $next - $current,
                ];
            }
        }

        $cap = $policy->rules['exceptional'];

        if ($policy->exceptional($points) < $cap['cap']) {
            return [
                'points' => $points + 1,
                'needed' => 1.0,
                'label' => 'Exceptional-Performance KES '.number_format($cap['per_point']).' per point',
                'gain' => (int) $cap['per_point'],
            ];
        }

        return null;
    }
}
