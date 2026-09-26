<?php

namespace App\Incentives;

/**
 * A salesperson's incentive figures for one month.
 */
final readonly class Earnings
{
    /**
     * @param  array<int, float>  $weeklyPoints  approved points per bonus week (1–4)
     * @param  array<int, float>  $weeklyProvisional  provisional points per bonus week
     * @param  array<int, int>  $weeklyBonuses
     * @param  list<array{label: string, amount: float}>  $adjustmentLines
     */
    public function __construct(
        public float $points,
        public float $provisionalPoints,
        public array $weeklyPoints,
        public array $weeklyProvisional,
        public int $retainer,
        public bool $retainerCompliant,
        public array $weeklyBonuses,
        public int $monthlyBonus,
        public int $exceptional,
        public float $airtime,
        public float $transport,
        public float $adjustments,
        public array $adjustmentLines,
    ) {}

    public function weeklyBonusTotal(): int
    {
        return array_sum($this->weeklyBonuses);
    }

    public function incentives(): int
    {
        return $this->retainer + $this->weeklyBonusTotal() + $this->monthlyBonus + $this->exceptional;
    }

    public function total(): float
    {
        return $this->incentives() + $this->airtime + $this->transport + $this->adjustments;
    }
}
