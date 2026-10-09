<?php

namespace App\Support\Travel;

use App\Enums\Travel\TravelBookingStatus;
use App\Enums\Travel\TravelTargetMetric;
use App\Models\FlightBooking;
use App\Models\PackageBooking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Travel sales figures per salesperson for a period: the numbers behind
 * travel targets, the dashboards and Travel reports.
 *
 * Counting rules:
 * - Flight bookings count on their booking date and exclude cancelled ones
 *   (cancellation status or cancelled date set). Revenue is the total amount.
 * - Package bookings count on their creation date when confirmed or
 *   completed. Revenue is the booking total.
 */
class TravelSalesMetrics
{
    /**
     * @return Builder<FlightBooking>
     */
    public static function flightSales(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return FlightBooking::query()
            ->whereBetween('booked_at', [$from, $to])
            ->whereNull('cancelled_at')
            ->whereNull('cancellation_status');
    }

    /**
     * @return Builder<PackageBooking>
     */
    public static function packageSales(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return PackageBooking::query()
            ->whereBetween('package_bookings.created_at', [$from, $to])
            ->whereIn('package_bookings.status', [TravelBookingStatus::Confirmed, TravelBookingStatus::Completed]);
    }

    /**
     * Actual figure per salesperson for each target metric.
     *
     * @param  list<int>|null  $userIds  null = everyone
     * @return Collection<int, array<string, float>> user id => [metric value => amount]
     */
    public static function bySalesperson(CarbonInterface $from, CarbonInterface $to, ?array $userIds = null): Collection
    {
        $flights = self::flightSales($from, $to)
            ->whereNotNull('salesperson_id')
            ->when($userIds !== null, fn (Builder $query) => $query->whereIn('salesperson_id', $userIds))
            ->selectRaw('salesperson_id, count(*) as bookings, coalesce(sum(total_amount), 0) as revenue')
            ->groupBy('salesperson_id')
            ->get()
            ->keyBy('salesperson_id');

        $packages = self::packageSales($from, $to)
            ->whereNotNull('salesperson_id')
            ->when($userIds !== null, fn (Builder $query) => $query->whereIn('salesperson_id', $userIds))
            ->selectRaw('salesperson_id, count(*) as bookings, coalesce(sum(amount_total), 0) as revenue')
            ->groupBy('salesperson_id')
            ->get()
            ->keyBy('salesperson_id');

        return $flights->keys()->merge($packages->keys())->unique()->mapWithKeys(fn (int $userId): array => [
            $userId => [
                TravelTargetMetric::FlightBookings->value => (float) ($flights[$userId]->bookings ?? 0),
                TravelTargetMetric::FlightRevenue->value => (float) ($flights[$userId]->revenue ?? 0),
                TravelTargetMetric::TourBookings->value => (float) ($packages[$userId]->bookings ?? 0),
                TravelTargetMetric::TourRevenue->value => (float) ($packages[$userId]->revenue ?? 0),
            ],
        ]);
    }

    /**
     * Flight and tour sales over a period, in day, week or month buckets
     * (picked from the length of the period unless given). Every bucket in
     * the period is present, empty ones as zero, for charts.
     *
     * @param  'day'|'week'|'month'|null  $unit
     * @return list<array{label: string, starts: string, flight_bookings: int, flight_revenue: float, tour_bookings: int, tour_revenue: float}>
     */
    public static function trend(CarbonInterface $from, CarbonInterface $to, ?int $userId = null, ?string $unit = null): array
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to)->endOfDay();
        $days = (int) round($from->diffInDays($to->startOfDay())) + 1;
        $unit ??= match (true) {
            $days <= 31 => 'day',
            $days <= 126 => 'week',
            default => 'month',
        };
        $startOf = fn (CarbonInterface $date): CarbonImmutable => match ($unit) {
            'day' => CarbonImmutable::instance($date)->startOfDay(),
            'week' => CarbonImmutable::instance($date)->startOfWeek(CarbonInterface::MONDAY),
            default => CarbonImmutable::instance($date)->startOfMonth(),
        };
        $spansYears = $from->year !== $to->year;

        $buckets = [];

        for ($cursor = $startOf($from); $cursor->lte($to); $cursor = $cursor->add(1, $unit)) {
            $buckets[$cursor->toDateString()] = [
                'label' => match ($unit) {
                    'month' => $cursor->format($spansYears ? 'M Y' : 'M'),
                    default => $cursor->format('j M'),
                },
                'starts' => $cursor->toDateString(),
                'flight_bookings' => 0,
                'flight_revenue' => 0.0,
                'tour_bookings' => 0,
                'tour_revenue' => 0.0,
            ];
        }

        $flights = self::flightSales($from, $to)->when($userId, fn (Builder $query) => $query->where('salesperson_id', $userId))
            ->toBase()->get(['booked_at as at', 'total_amount as amount']);
        $tours = self::packageSales($from, $to)->when($userId, fn (Builder $query) => $query->where('package_bookings.salesperson_id', $userId))
            ->toBase()->get(['package_bookings.created_at as at', 'package_bookings.amount_total as amount']);

        foreach (['flight' => $flights, 'tour' => $tours] as $kind => $rows) {
            foreach ($rows as $row) {
                $key = $startOf(CarbonImmutable::parse($row->at))->toDateString();

                if (isset($buckets[$key])) {
                    $buckets[$key][$kind.'_bookings']++;
                    $buckets[$key][$kind.'_revenue'] += (float) $row->amount;
                }
            }
        }

        return array_values($buckets);
    }

    /**
     * @return array<string, float>
     */
    public static function totals(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): array
    {
        $flights = self::flightSales($from, $to)->when($userId, fn (Builder $query) => $query->where('salesperson_id', $userId));
        $packages = self::packageSales($from, $to)->when($userId, fn (Builder $query) => $query->where('salesperson_id', $userId));

        return [
            TravelTargetMetric::FlightBookings->value => (float) (clone $flights)->count(),
            TravelTargetMetric::FlightRevenue->value => (float) (clone $flights)->sum('total_amount'),
            TravelTargetMetric::TourBookings->value => (float) (clone $packages)->count(),
            TravelTargetMetric::TourRevenue->value => (float) (clone $packages)->sum('amount_total'),
        ];
    }
}
