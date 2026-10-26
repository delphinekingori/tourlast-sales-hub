<?php

namespace App\Support;

use App\Enums\OnboardingStatus;
use App\Models\ExpenseClaim;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\PayoutStatement;
use App\Models\User;
use App\Support\Travel\TravelSalesMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The numbers on the home dashboard for managers, admins, HR and Accounts:
 * how many partners went live, where signups are stuck, who is selling, what
 * money is waiting, and how travel is doing. Money is never added across
 * currencies.
 */
class HubOverview
{
    /**
     * Partners that went live this month against last month, the signup funnel
     * and the six-month trend.
     *
     * @return array{
     *     month: int, lastMonth: int, delta: int, live: int, openLeads: int, newLeads: int, stalled: int,
     *     funnel: array<string, int>, trend: list<array{label: string, starts: string, onboarded: int}>
     * }
     */
    public function onboarding(): array
    {
        $monthStart = CarbonImmutable::now()->startOfMonth();
        $month = Onboarding::query()->creditedBetween($monthStart, $monthStart->endOfMonth())->count();
        $lastStart = $monthStart->subMonthNoOverflow();
        $lastMonth = Onboarding::query()->creditedBetween($lastStart, $lastStart->endOfMonth())->count();

        $byStatus = Onboarding::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $funnel = [];

        foreach ([OnboardingStatus::Submitted, OnboardingStatus::UnderReview, OnboardingStatus::Approved, OnboardingStatus::Active] as $status) {
            $funnel[$status->label()] = (int) ($byStatus[$status->value] ?? 0);
        }

        $trend = [];

        foreach (range(5, 0) as $back) {
            $start = $monthStart->subMonthsNoOverflow($back);
            $trend[] = [
                'label' => $start->format('M'),
                'starts' => $start->toDateString(),
                'onboarded' => Onboarding::query()->creditedBetween($start, $start->endOfMonth())->count(),
            ];
        }

        return [
            'month' => $month,
            'lastMonth' => $lastMonth,
            'delta' => $month - $lastMonth,
            'live' => Onboarding::query()->onboarded()->count(),
            'openLeads' => Lead::query()->open()->count(),
            'newLeads' => Lead::query()->whereBetween('created_at', [$monthStart, $monthStart->endOfMonth()])->count(),
            'stalled' => Onboarding::query()->awaitingApproval()->where('submitted_at', '<', now()->subDays((int) config('hub.stalled_after_days')))->count(),
            'funnel' => $funnel,
            'trend' => $trend,
        ];
    }

    /**
     * The five salespeople with the most partners live this month.
     *
     * @return Collection<int, array{user: User, onboarded: int}>
     */
    public function topSalespeople(): Collection
    {
        $monthStart = CarbonImmutable::now()->startOfMonth();
        $counts = Onboarding::query()
            ->creditedBetween($monthStart, $monthStart->endOfMonth())
            ->whereNotNull('user_id')
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(5)
            ->pluck('total', 'user_id');

        $users = User::query()->whereIn('id', $counts->keys())->get()->keyBy('id');

        return $counts->map(fn ($total, $userId): array => ['user' => $users[$userId], 'onboarded' => (int) $total])
            ->filter(fn (array $row): bool => $row['user'] instanceof User)
            ->values();
    }

    /**
     * Claims waiting and this month's payout statements, in KES.
     *
     * @return array{claimsWaiting: int, claimsAmount: float, statementsToPay: float, statementsPaid: float}
     */
    public function money(): array
    {
        $month = CarbonImmutable::now()->startOfMonth()->toDateString();
        $claims = ExpenseClaim::query()->where('status', 'pending');
        $statements = PayoutStatement::query()->whereDate('month', $month);

        return [
            'claimsWaiting' => (clone $claims)->count(),
            'claimsAmount' => (float) (clone $claims)->sum('amount'),
            'statementsToPay' => (float) (clone $statements)->where('status', 'approved')->sum('total'),
            'statementsPaid' => (float) (clone $statements)->where('status', 'paid')->sum('total'),
        ];
    }

    /**
     * Travel sales this month. Flights are in USD and tours in KES.
     *
     * @return array{flightBookings: int, flightRevenue: float, tourBookings: int, tourRevenue: float}
     */
    public function travel(): array
    {
        $totals = TravelSalesMetrics::totals(now()->startOfMonth(), now()->endOfMonth());

        return [
            'flightBookings' => (int) $totals['flight_bookings'],
            'flightRevenue' => $totals['flight_revenue'],
            'tourBookings' => (int) $totals['tour_bookings'],
            'tourRevenue' => $totals['tour_revenue'],
        ];
    }
}
