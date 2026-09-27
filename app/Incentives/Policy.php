<?php

namespace App\Incentives;

use Carbon\CarbonInterface;

/**
 * The rules of an incentive schedule, read from an IncentivePolicy's JSON.
 *
 * Money is in whole KES. Points move in steps of 0.5.
 */
final readonly class Policy
{
    /**
     * @param  array<string, mixed>  $rules
     */
    public function __construct(public array $rules) {}

    /**
     * Schedule 1 — Property and Experience Acquisition Incentive Policy.
     *
     * @return array<string, mixed>
     */
    public static function schedule1(): array
    {
        return [
            'stay_bands' => [[1, 10, 1], [11, 50, 3], [51, 100, 5], [101, 200, 7], [201, null, 9]],
            'experience_bands' => [[1, 5, 1], [6, 15, 2], [16, 30, 3], [31, 50, 4], [51, null, 5]],
            'retainer' => [[18, 7500], [30, 15000]],
            'weekly_bonus' => ['threshold' => 18, 'amount' => 1500],
            'bonus_weeks' => [[1, 7], [8, 14], [15, 21], [22, 31]],
            'monthly_bonus' => ['above_zero' => 2500, 'bands' => [[26, 5000], [36, 7500], [46, 10000], [56, 12500], [66, 15000], [76, 17500]]],
            'exceptional' => ['above' => 76, 'per_point' => 300, 'cap' => 10000],
            'airtime_cap' => 400,
            'expansion_days' => 90,
            'review_days' => 14,
            'same_category_award' => 0.5,
            'same_category_growth' => 0.5,
            'payment_day' => 5,
        ];
    }

    public function basePoints(string $category, ?int $inventory): float
    {
        if (! $inventory || $inventory < 1) {
            return 0;
        }

        foreach ($this->rules[$category === 'experience' ? 'experience_bands' : 'stay_bands'] as [$min, $max, $points]) {
            if ($inventory >= $min && ($max === null || $inventory <= $max)) {
                return (float) $points;
            }
        }

        return 0;
    }

    /**
     * The band index (0-based) an inventory falls into, used to tell "same category" growth apart.
     */
    public function bandIndex(string $category, int $inventory): int
    {
        foreach ($this->rules[$category === 'experience' ? 'experience_bands' : 'stay_bands'] as $index => [$min, $max]) {
            if ($inventory >= $min && ($max === null || $inventory <= $max)) {
                return $index;
            }
        }

        return -1;
    }

    public function maxBasePoints(string $category): float
    {
        $bands = $this->rules[$category === 'experience' ? 'experience_bands' : 'stay_bands'];

        return (float) end($bands)[2];
    }

    /**
     * @return list<array{0: int, 1: ?int, 2: int}>
     */
    public function bands(string $category): array
    {
        return $this->rules[$category === 'experience' ? 'experience_bands' : 'stay_bands'];
    }

    public function retainer(float $points): int
    {
        $amount = 0;

        foreach ($this->rules['retainer'] as [$threshold, $value]) {
            if ($points >= $threshold) {
                $amount = $value;
            }
        }

        return $amount;
    }

    public function weeklyBonus(float $weekPoints): int
    {
        return $weekPoints >= $this->rules['weekly_bonus']['threshold'] ? $this->rules['weekly_bonus']['amount'] : 0;
    }

    public function monthlyBonus(float $points): int
    {
        if ($points <= 0) {
            return 0;
        }

        $amount = $this->rules['monthly_bonus']['above_zero'];

        foreach ($this->rules['monthly_bonus']['bands'] as [$threshold, $value]) {
            if ($points >= $threshold) {
                $amount = $value;
            }
        }

        return $amount;
    }

    public function exceptional(float $points): int
    {
        $rule = $this->rules['exceptional'];
        $above = max(0, $points - $rule['above']);

        return (int) min($rule['cap'], round($above * $rule['per_point']));
    }

    public function bonusWeek(CarbonInterface $date): int
    {
        foreach ($this->rules['bonus_weeks'] as $index => [$from, $to]) {
            if ($date->day >= $from && $date->day <= $to) {
                return $index + 1;
            }
        }

        return count($this->rules['bonus_weeks']);
    }

    /**
     * @return list<array{week: int, from: int, to: int}>
     */
    public function weeks(CarbonInterface $month): array
    {
        return array_map(fn (array $week, int $index): array => [
            'week' => $index + 1,
            'from' => $week[0],
            'to' => min($week[1], $month->daysInMonth),
        ], $this->rules['bonus_weeks'], array_keys($this->rules['bonus_weeks']));
    }

    public function retainerThresholds(): array
    {
        return array_map(fn (array $row): float => (float) $row[0], $this->rules['retainer']);
    }

    /**
     * Every points level where pay steps up, with what it unlocks.
     *
     * @return list<array{points: float, label: string, gain: int}>
     */
    public function steps(): array
    {
        $steps = [];

        foreach ($this->rules['retainer'] as [$threshold, $value]) {
            $steps[] = ['points' => (float) $threshold, 'label' => 'Retainer KES '.number_format($value), 'kind' => 'retainer'];
        }

        foreach ($this->rules['monthly_bonus']['bands'] as [$threshold, $value]) {
            $steps[] = ['points' => (float) $threshold, 'label' => 'Monthly Bonus KES '.number_format($value), 'kind' => 'monthly'];
        }

        usort($steps, fn (array $a, array $b): int => $a['points'] <=> $b['points']);

        return $steps;
    }

    public function airtimeCap(): int
    {
        return (int) $this->rules['airtime_cap'];
    }

    public function expansionDays(): int
    {
        return (int) $this->rules['expansion_days'];
    }

    public function reviewDays(): int
    {
        return (int) $this->rules['review_days'];
    }

    public function sameCategoryAward(): float
    {
        return (float) $this->rules['same_category_award'];
    }

    public function sameCategoryGrowth(): float
    {
        return (float) $this->rules['same_category_growth'];
    }

    public function paymentDay(): int
    {
        return (int) $this->rules['payment_day'];
    }
}
