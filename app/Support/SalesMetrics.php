<?php

namespace App\Support;

use App\Enums\OnboardingStatus;
use App\Models\Activity;
use App\Models\Onboarding;
use App\Models\PointEntry;
use App\Models\ReferralClick;
use App\Models\Target;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The numbers behind My Progress, Team Performance and the reports.
 *
 * "Onboarded" follows Schedule 1: counted on the Activation Date, when the
 * provider is live on tourlast.com. Targets are in points.
 */
class SalesMetrics
{
    /**
     * @return array{
     *     target: ?int, points: float, approvedPoints: float, onboarded: int, active: int, approvedNotLive: int,
     *     awaiting: int, stalled: int, submitted: int, clicks: int, conversion: ?float, activities: int,
     *     byType: array<string, int>, previousOnboarded: int, previousPoints: float, lastActivityAt: ?CarbonImmutable
     * }
     */
    public function forUser(User $user, Period $period): array
    {
        $onboarded = Onboarding::query()->where('user_id', $user->id)->creditedBetween($period->from, $period->to)->get(['id', 'status', 'property_type']);
        $previous = $this->previousPeriod($period);
        $clicks = ReferralClick::query()
            ->whereIn('referral_code_id', $user->referralCodes()->select('id'))
            ->whereBetween('clicked_at', [$period->from, $period->to])
            ->count();
        $submitted = Onboarding::query()->where('user_id', $user->id)->whereBetween('submitted_at', [$period->from, $period->to])->count();
        $awaiting = Onboarding::query()->where('user_id', $user->id)->awaitingApproval();
        $points = PointEntry::query()->where('user_id', $user->id)->counting()->whereBetween('earned_on', [$period->from, $period->to]);

        return [
            'target' => $this->targetFor($user, $period),
            'points' => (float) (clone $points)->sum('points'),
            'approvedPoints' => (float) (clone $points)->where('status', 'approved')->sum('points'),
            'previousPoints' => (float) PointEntry::query()->where('user_id', $user->id)->counting()->whereBetween('earned_on', [$previous->from, $previous->to])->sum('points'),
            'onboarded' => $onboarded->count(),
            'active' => $onboarded->where('status', OnboardingStatus::Active)->count(),
            'approvedNotLive' => (clone $awaiting)->where('status', OnboardingStatus::Approved)->count(),
            'awaiting' => (clone $awaiting)->count(),
            'stalled' => (clone $awaiting)->where('status', '!=', OnboardingStatus::Approved)->where('submitted_at', '<', now()->subDays(config('hub.stalled_after_days')))->count(),
            'submitted' => $submitted,
            'clicks' => $clicks,
            'conversion' => $clicks > 0 ? round($onboarded->count() / $clicks * 100, 1) : null,
            'activities' => Activity::query()->where('user_id', $user->id)->whereBetween('happened_at', [$period->from, $period->to])->count(),
            'byType' => $onboarded->countBy('property_type')->sortDesc()->all(),
            'previousOnboarded' => Onboarding::query()->where('user_id', $user->id)->creditedBetween($previous->from, $previous->to)->count(),
            'lastActivityAt' => ($last = Activity::query()->where('user_id', $user->id)->max('happened_at')) ? CarbonImmutable::parse($last) : null,
        ];
    }

    /**
     * Sum of the monthly targets inside the period, or null when none were set.
     */
    public function targetFor(User $user, Period $period): ?int
    {
        $targets = Target::query()
            ->where('user_id', $user->id)
            ->whereBetween('month', [$period->from->startOfMonth()->toDateString(), $period->to->toDateString()])
            ->pluck('target');

        if ($targets->isEmpty()) {
            return null;
        }

        if ($period->key === 'week') {
            return (int) ceil($targets->first() / 4.345);
        }

        return (int) $targets->sum();
    }

    /**
     * Onboarded per month for the last $months months, oldest first.
     *
     * Points (and partners gone live) per month, oldest first.
     *
     * @return list<array{month: CarbonImmutable, label: string, count: int, points: float, target: ?int}>
     */
    public function monthlyTrend(User $user, int $months = 6): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);

        $counts = Onboarding::query()
            ->where('user_id', $user->id)
            ->onboarded()
            ->where('credited_at', '>=', $start)
            ->pluck('credited_at')
            ->countBy(fn ($date): string => CarbonImmutable::parse($date)->format('Y-m'));

        $points = PointEntry::query()
            ->where('user_id', $user->id)
            ->counting()
            ->where('earned_on', '>=', $start)
            ->get(['points', 'month'])
            ->groupBy(fn (PointEntry $entry): string => $entry->month->format('Y-m'))
            ->map(fn ($entries): float => (float) $entries->sum('points'));

        $targets = Target::query()
            ->where('user_id', $user->id)
            ->where('month', '>=', $start->toDateString())
            ->get()
            ->keyBy(fn (Target $target): string => $target->month->format('Y-m'));

        $trend = [];

        for ($month = $start; $month->lte(CarbonImmutable::now()); $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $trend[] = [
                'month' => $month,
                'label' => $month->format('M'),
                'count' => (int) ($counts[$key] ?? 0),
                'points' => (float) ($points[$key] ?? 0),
                'target' => $targets->get($key)?->target,
            ];
        }

        return $trend;
    }

    /**
     * One row per active salesperson for Team Performance.
     *
     * @return Collection<int, array{user: User, metrics: array<string, mixed>, status: array{label: string, tone: string}, progress: ?float}>
     */
    public function team(Period $period, ?string $region = null): Collection
    {
        return User::query()
            ->active()
            ->sellers()
            ->with('roles')
            ->when($region, fn ($query) => $query->where('region', $region))
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($period): array {
                $metrics = $this->forUser($user, $period);

                return [
                    'user' => $user,
                    'metrics' => $metrics,
                    'status' => $this->status($metrics, $period),
                    'progress' => $metrics['target'] ? min(1, $metrics['points'] / max(1, $metrics['target'])) : null,
                ];
            })
            ->sortByDesc(fn (array $row): float => $row['metrics']['points'])
            ->values();
    }

    /**
     * Where a salesperson stands: inactive first, then pace against their own target.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{label: string, tone: string}
     */
    public function status(array $metrics, Period $period): array
    {
        $inactiveDays = config('hub.inactive_after_days');
        /** @var ?CarbonImmutable $lastActivity */
        $lastActivity = $metrics['lastActivityAt'];

        if (! $lastActivity || $lastActivity->lt(now()->subDays($inactiveDays))) {
            $days = $lastActivity ? (int) $lastActivity->diffInDays(now()) : null;

            return ['label' => $days ? "Inactive {$days} days" : 'No activity yet', 'tone' => 'danger'];
        }

        if (! $metrics['target']) {
            return ['label' => 'No target set', 'tone' => 'neutral'];
        }

        if ($metrics['points'] >= $metrics['target']) {
            return ['label' => 'Target hit', 'tone' => 'success'];
        }

        $expected = $metrics['target'] * $period->elapsedFraction();

        return $metrics['points'] >= $expected * 0.85
            ? ['label' => 'On track', 'tone' => 'brand']
            : ['label' => 'Behind', 'tone' => 'warning'];
    }

    private function previousPeriod(Period $period): Period
    {
        return match ($period->key) {
            'week' => new Period('week', $period->from->subWeek(), $period->to->subWeek()),
            'quarter' => new Period('quarter', $period->from->subQuarter(), $period->from->subQuarter()->lastOfQuarter()->endOfDay()),
            'year' => new Period('year', $period->from->subYear(), $period->to->subYear()),
            'month' => new Period('month', $period->from->subMonth(), $period->from->subMonth()->endOfMonth()),
            default => new Period('custom', $period->from->sub($period->from->diff($period->to))->subDay(), $period->from->subSecond()),
        };
    }
}
