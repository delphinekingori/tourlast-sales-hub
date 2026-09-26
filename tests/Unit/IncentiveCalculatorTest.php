<?php

namespace Tests\Unit;

use App\Incentives\Calculator;
use App\Incentives\Policy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every figure and worked example in Schedule 1.
 */
class IncentiveCalculatorTest extends TestCase
{
    private Policy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new Policy(Policy::schedule1());
    }

    /**
     * @return array<string, array{string, int, float}>
     */
    public static function accountSizes(): array
    {
        return [
            'stay 1 room' => ['stay', 1, 1], 'stay 10 rooms' => ['stay', 10, 1], 'stay 11 rooms' => ['stay', 11, 3],
            'stay 50 rooms' => ['stay', 50, 3], 'stay 90 rooms (10+30+50, paragraph 3.4)' => ['stay', 90, 5],
            'stay 100 rooms' => ['stay', 100, 5], 'stay 120 rooms' => ['stay', 120, 7], 'stay 200 rooms' => ['stay', 200, 7],
            'stay 201 rooms' => ['stay', 201, 9], 'stay 900 rooms' => ['stay', 900, 9],
            'experience 5 services' => ['experience', 5, 1], 'experience 6 services' => ['experience', 6, 2],
            'experience 15 services' => ['experience', 15, 2], 'experience 16 services' => ['experience', 16, 3],
            'experience 30 services' => ['experience', 30, 3], 'experience 31 services' => ['experience', 31, 4],
            'experience 50 services' => ['experience', 50, 4], 'experience 51 services' => ['experience', 51, 5],
            'no inventory' => ['stay', 0, 0],
        ];
    }

    #[DataProvider('accountSizes')]
    public function test_account_points_follow_the_tables(string $category, int $inventory, float $points): void
    {
        $this->assertSame($points, $this->policy->basePoints($category, $inventory));
    }

    /**
     * @return array<string, array{float, int}>
     */
    public static function retainers(): array
    {
        return ['17 points' => [17, 0], '17.5 points (5.1)' => [17.5, 0], '18 points' => [18, 7500], '29.5 points (5.1)' => [29.5, 7500], '30 points' => [30, 15000], '95 points' => [95, 15000]];
    }

    #[DataProvider('retainers')]
    public function test_the_performance_retainer(float $points, int $amount): void
    {
        $this->assertSame($amount, $this->policy->retainer($points));
    }

    /**
     * @return array<string, array{float, int}>
     */
    public static function monthlyBonuses(): array
    {
        return [
            '0 points' => [0, 0], '0.5 points' => [0.5, 2500], '25.5 points (7.5)' => [25.5, 2500], '26 points' => [26, 5000],
            '35.5 points (7.5)' => [35.5, 5000], '36 points' => [36, 7500], '46 points' => [46, 10000], '56 points' => [56, 12500],
            '66 points' => [66, 15000], '75.5 points' => [75.5, 15000], '76 points' => [76, 17500], '150 points' => [150, 17500],
        ];
    }

    #[DataProvider('monthlyBonuses')]
    public function test_the_monthly_performance_bonus(float $points, int $amount): void
    {
        $this->assertSame($amount, $this->policy->monthlyBonus($points));
    }

    /**
     * @return array<string, array{float, int}>
     */
    public static function exceptionalPayments(): array
    {
        return [
            '76 points' => [76, 0], '76.5 points (half point, 8.2)' => [76.5, 150], '86 points (8.5)' => [86, 3000],
            '109 points' => [109, 9900], '109.5 points (cap, 8.4)' => [109.5, 10000], '110 points' => [110, 10000], '200 points' => [200, 10000],
        ];
    }

    #[DataProvider('exceptionalPayments')]
    public function test_the_exceptional_performance_payment(float $points, int $amount): void
    {
        $this->assertSame($amount, $this->policy->exceptional($points));
    }

    public function test_bonus_weeks_are_fixed_calendar_ranges(): void
    {
        $this->assertSame(1, $this->policy->bonusWeek(CarbonImmutable::parse('2026-09-07')));
        $this->assertSame(2, $this->policy->bonusWeek(CarbonImmutable::parse('2026-09-08')));
        $this->assertSame(3, $this->policy->bonusWeek(CarbonImmutable::parse('2026-09-21')));
        $this->assertSame(4, $this->policy->bonusWeek(CarbonImmutable::parse('2026-09-22')));
        $this->assertSame(4, $this->policy->bonusWeek(CarbonImmutable::parse('2026-10-31')));
        $this->assertSame(28, $this->policy->weeks(CarbonImmutable::parse('2026-02-01'))[3]['to']);
    }

    public function test_weekly_bonuses_pay_once_per_week_and_never_carry_over(): void
    {
        $earnings = (new Calculator)->calculate($this->policy, [1 => 17.5, 2 => 18, 3 => 40, 4 => 18]);

        $this->assertSame([1 => 0, 2 => 1500, 3 => 1500, 4 => 1500], $earnings->weeklyBonuses);
        $this->assertSame(4500, $earnings->weeklyBonusTotal());

        $all = (new Calculator)->calculate($this->policy, [1 => 20, 2 => 20, 3 => 20, 4 => 20]);
        $this->assertSame(6000, $all->weeklyBonusTotal());
    }

    public function test_a_full_month_matches_the_worked_example_in_the_plan(): void
    {
        $earnings = (new Calculator)->calculate($this->policy, [1 => 8, 2 => 19, 3 => 4.5, 4 => 0], [4 => 6], true, 400, 1200);

        $this->assertSame(31.5, $earnings->points);
        $this->assertSame(15000, $earnings->retainer);
        $this->assertSame(5000, $earnings->monthlyBonus);
        $this->assertSame(1500, $earnings->weeklyBonusTotal());
        $this->assertSame(0, $earnings->exceptional);
        $this->assertSame(23100.0, $earnings->total());

        $withPending = (new Calculator)->withProvisional($this->policy, $earnings);
        $this->assertSame(37.5, $withPending->points);
        $this->assertSame(25600.0, $withPending->total());
    }

    public function test_the_retainer_needs_the_reporting_conditions(): void
    {
        $earnings = (new Calculator)->calculate($this->policy, [1 => 40], [], false);

        $this->assertSame(0, $earnings->retainer);
        $this->assertSame(7500, $earnings->monthlyBonus);
    }

    public function test_airtime_is_capped_at_400(): void
    {
        $this->assertSame(400.0, (new Calculator)->calculate($this->policy, [], [], true, 900)->airtime);
    }

    public function test_the_maximum_month(): void
    {
        $earnings = (new Calculator)->calculate($this->policy, [1 => 30, 2 => 30, 3 => 30, 4 => 30], [], true, 400);

        $this->assertSame(15000 + 6000 + 17500 + 10000 + 400, (int) $earnings->total());
    }

    public function test_the_next_step_names_the_nearest_pay_rise(): void
    {
        $calculator = new Calculator;

        $this->assertSame(['points' => 36.0, 'needed' => 4.5, 'label' => 'Monthly Bonus KES 7,500', 'gain' => 2500], $calculator->nextStep($this->policy, 31.5));
        $this->assertSame(7500, $calculator->nextStep($this->policy, 17.5)['gain']);
        $this->assertSame(300, $calculator->nextStep($this->policy, 80)['gain']);
        $this->assertNull($calculator->nextStep($this->policy, 110));
    }
}
