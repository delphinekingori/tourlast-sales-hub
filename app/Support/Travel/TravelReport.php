<?php

namespace App\Support\Travel;

use App\Enums\Role;
use App\Enums\Travel\ApprovalDecision;
use App\Enums\Travel\ApprovalLevel;
use App\Enums\Travel\ContractStatus;
use App\Enums\Travel\PackageVersionStatus;
use App\Enums\Travel\RefundStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Enums\Travel\TravelProviderStatus;
use App\Enums\Travel\TravelTargetMetric;
use App\Models\FlightBooking;
use App\Models\PackageApproval;
use App\Models\PackageBooking;
use App\Models\PackageVersion;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Models\TravelRefund;
use App\Models\TravelTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Travel reports for a period: flights, tours, providers, approvals and
 * salespeople. Travel salespeople get their own figures only; Travel
 * managers and Accounts get everyone's (optionally one salesperson's).
 * Each section is a list of rows so the page and the Excel export share it.
 */
class TravelReport
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $userId,
        public bool $withMarkup,
    ) {}

    public static function for(User $viewer, CarbonImmutable $from, CarbonImmutable $to, ?int $salespersonId = null): self
    {
        $seesAll = TravelAccess::managesAll($viewer) || TravelAccess::seesFinancials($viewer);

        return new self($from->startOfDay(), $to->endOfDay(), $seesAll ? $salespersonId : $viewer->id, TravelAccess::seesFinancials($viewer));
    }

    /**
     * @return array<string, float|int>
     */
    public function flightSummary(): array
    {
        $sales = $this->flights(TravelSalesMetrics::flightSales($this->from, $this->to));
        $all = $this->flights(FlightBooking::query()->whereBetween('booked_at', [$this->from, $this->to]));

        return array_filter([
            'Bookings' => (clone $sales)->count(),
            'Revenue (KES)' => (float) (clone $sales)->sum('total_amount'),
            'Markup (KES)' => $this->withMarkup ? (float) (clone $sales)->sum('markup_amount') : null,
            'Passengers' => (int) (clone $sales)->sum('passenger_count'),
            'Cancelled bookings' => (clone $all)->where(fn (Builder $query) => $query->whereNotNull('cancelled_at')->orWhereNotNull('cancellation_status'))->count(),
            'Refunds requested' => (clone $all)->whereNotNull('refund_status')->count(),
            'Refund value (KES)' => (float) (clone $all)->whereNotNull('refund_status')->sum('refund_amount'),
        ], fn ($value) => $value !== null);
    }

    /**
     * Flight and tour sales across the period, for the trend charts.
     *
     * @return list<array{label: string, starts: string, flight_bookings: int, flight_revenue: float, tour_bookings: int, tour_revenue: float}>
     */
    public function trend(): array
    {
        return TravelSalesMetrics::trend($this->from, $this->to, $this->userId);
    }

    /**
     * @return list<array<string, string|int|float>>
     */
    public function flightRefunds(): array
    {
        return $this->flights(FlightBooking::query()->whereBetween('booked_at', [$this->from, $this->to])->whereNotNull('refund_status'))
            ->selectRaw('refund_status, count(*) as total, coalesce(sum(refund_amount), 0) as amount')
            ->groupBy('refund_status')->orderByDesc('total')->get()
            ->map(fn ($row) => ['Refund status' => ucfirst(str_replace('_', ' ', $row->refund_status)), 'Bookings' => (int) $row->total, 'Amount (KES)' => (float) $row->amount])
            ->all();
    }

    /**
     * @return list<array<string, string|int|float>>
     */
    public function routes(int $limit = 15): array
    {
        return $this->flights(TravelSalesMetrics::flightSales($this->from, $this->to))
            ->selectRaw('origin, destination, count(*) as total, coalesce(sum(total_amount), 0) as revenue')
            ->groupBy('origin', 'destination')->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($row) => ['Route' => $row->origin.' → '.$row->destination, 'Bookings' => (int) $row->total, 'Revenue (KES)' => (float) $row->revenue])
            ->all();
    }

    /**
     * @return list<array<string, string|int|float>>
     */
    public function airlines(): array
    {
        return $this->flights(TravelSalesMetrics::flightSales($this->from, $this->to))
            ->selectRaw('coalesce(airline_name, airline_code) as airline, count(*) as total, coalesce(sum(total_amount), 0) as revenue')
            ->groupBy(DB::raw('coalesce(airline_name, airline_code)'))->orderByDesc('total')->get()
            ->map(fn ($row) => ['Airline' => (string) $row->airline, 'Bookings' => (int) $row->total, 'Revenue (KES)' => (float) $row->revenue])
            ->all();
    }

    /**
     * @return array<string, float|int>
     */
    public function tourSummary(): array
    {
        $sales = $this->packages(TravelSalesMetrics::packageSales($this->from, $this->to));
        $created = $this->packages(PackageBooking::query()->whereBetween('created_at', [$this->from, $this->to]));

        return [
            'Bookings' => (clone $sales)->count(),
            'Revenue (KES)' => (float) (clone $sales)->sum('amount_total'),
            'Slots sold' => (int) (clone $sales)->sum('travelers'),
            'Collected (KES)' => (float) (clone $sales)->sum('amount_paid'),
            'Cancelled bookings' => (clone $created)->where('status', TravelBookingStatus::Cancelled)->count(),
            'Refunds paid (KES)' => (float) TravelRefund::query()->where('status', RefundStatus::Completed)
                ->whereBetween('processed_at', [$this->from, $this->to])
                ->whereHas('booking', fn (Builder $booking) => $this->packages($booking))
                ->sum('amount'),
        ];
    }

    /**
     * @return list<array<string, string|int|float>>
     */
    public function packagePerformance(int $limit = 25): array
    {
        return $this->packages(PackageBooking::query()->whereBetween('package_bookings.created_at', [$this->from, $this->to]))
            ->join('packages', 'packages.id', '=', 'package_bookings.package_id')
            ->selectRaw('packages.name as package, packages.destination as destination, count(*) as total,
                sum(case when package_bookings.status in (?, ?) then package_bookings.travelers else 0 end) as slots,
                sum(case when package_bookings.status in (?, ?) then package_bookings.amount_total else 0 end) as revenue,
                sum(case when package_bookings.status = ? then 1 else 0 end) as cancelled,
                coalesce(sum(package_bookings.amount_refunded), 0) as refunded', [
                TravelBookingStatus::Confirmed->value, TravelBookingStatus::Completed->value,
                TravelBookingStatus::Confirmed->value, TravelBookingStatus::Completed->value,
                TravelBookingStatus::Cancelled->value,
            ])
            ->groupBy('packages.id', 'packages.name', 'packages.destination')
            ->orderByDesc('revenue')->limit($limit)->get()
            ->map(fn ($row) => [
                'Package' => $row->package,
                'Destination' => $row->destination,
                'Bookings' => (int) $row->total,
                'Slots sold' => (int) $row->slots,
                'Revenue (KES)' => (float) $row->revenue,
                'Cancellation rate' => $row->total ? round($row->cancelled / $row->total * 100).'%' : '0%',
                'Refunded (KES)' => (float) $row->refunded,
            ])->all();
    }

    /**
     * @return list<array<string, string|int|float>>
     */
    public function destinations(): array
    {
        return $this->packages(TravelSalesMetrics::packageSales($this->from, $this->to))
            ->join('packages', 'packages.id', '=', 'package_bookings.package_id')
            ->selectRaw('packages.destination as destination, count(*) as total, sum(package_bookings.travelers) as slots, coalesce(sum(package_bookings.amount_total), 0) as revenue')
            ->groupBy('packages.destination')->orderByDesc('revenue')->get()
            ->map(fn ($row) => ['Destination' => $row->destination, 'Bookings' => (int) $row->total, 'Travelers' => (int) $row->slots, 'Revenue (KES)' => (float) $row->revenue])
            ->all();
    }

    /**
     * @return list<array<string, string|int|float>>
     */
    public function providerPerformance(): array
    {
        $bookings = $this->packages(PackageBooking::query()->whereBetween('package_bookings.created_at', [$this->from, $this->to]))
            ->join('packages', 'packages.id', '=', 'package_bookings.package_id')
            ->selectRaw('packages.travel_provider_id as provider_id, count(*) as total,
                sum(case when package_bookings.status in (?, ?) then package_bookings.amount_total else 0 end) as revenue,
                sum(case when package_bookings.status = ? then 1 else 0 end) as cancelled', [
                TravelBookingStatus::Confirmed->value, TravelBookingStatus::Completed->value, TravelBookingStatus::Cancelled->value,
            ])
            ->groupBy('packages.travel_provider_id')->get()->keyBy('provider_id');

        return TravelProvider::query()->current()
            ->when($this->userId, fn (Builder $query) => $query->where('owner_id', $this->userId))
            ->withCount('packages')
            ->with(['owner:id,name', 'contracts'])
            ->orderBy('name')->get()
            ->map(function (TravelProvider $provider) use ($bookings): array {
                $row = $bookings[$provider->id] ?? null;
                $contract = $provider->activeContract();

                return [
                    'Provider' => $provider->name,
                    'Status' => $provider->status->label(),
                    'Packages' => (int) $provider->packages_count,
                    'Bookings' => (int) ($row->total ?? 0),
                    'Revenue (KES)' => (float) ($row->revenue ?? 0),
                    'Cancellations' => (int) ($row->cancelled ?? 0),
                    'Contract ends' => $contract?->ends_on?->format('j M Y') ?? ($contract ? 'Open-ended' : 'No active contract'),
                    'Salesperson' => (string) $provider->owner?->name,
                ];
            })
            ->sortByDesc('Revenue (KES)')->values()->all();
    }

    /**
     * @return array<string, int>
     */
    public function providerSummary(): array
    {
        $providers = TravelProvider::query()->current()->when($this->userId, fn (Builder $query) => $query->where('owner_id', $this->userId));
        $contracts = ProviderContract::query()->whereHas('provider', fn (Builder $provider) => $provider->current()->when($this->userId, fn (Builder $query) => $query->where('owner_id', $this->userId)));

        return [
            'Active providers' => (clone $providers)->whereIn('status', [TravelProviderStatus::Active, TravelProviderStatus::Contracted])->count(),
            'New providers' => (clone $providers)->whereBetween('created_at', [$this->from, $this->to])->count(),
            'Contracts active' => (clone $contracts)->withEffectiveStatus(ContractStatus::Active)->count(),
            'Contracts expiring soon' => (clone $contracts)->withEffectiveStatus(ContractStatus::ExpiringSoon)->count(),
            'Contracts expired' => (clone $contracts)->withEffectiveStatus(ContractStatus::Expired)->count(),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    public function approvalSummary(): array
    {
        $decisions = PackageApproval::query()->whereBetween('decided_at', [$this->from, $this->to])
            ->when($this->userId, fn (Builder $query) => $query->whereHas('package', fn (Builder $package) => $package->where('owner_id', $this->userId)));
        $approvedVersions = PackageVersion::query()->whereBetween('approved_at', [$this->from, $this->to])->whereNotNull('submitted_at')
            ->when($this->userId, fn (Builder $query) => $query->whereHas('package', fn (Builder $package) => $package->where('owner_id', $this->userId)))
            ->get(['submitted_at', 'approved_at']);
        $averageHours = $approvedVersions->isEmpty() ? null : $approvedVersions->avg(fn (PackageVersion $version) => $version->submitted_at->diffInMinutes($version->approved_at) / 60);

        return [
            'Pending now' => PackageVersion::query()->whereIn('status', [PackageVersionStatus::Submitted, PackageVersionStatus::SalesAdminApproved])
                ->when($this->userId, fn (Builder $query) => $query->whereHas('package', fn (Builder $package) => $package->where('owner_id', $this->userId)))
                ->count(),
            'Approved' => (clone $decisions)->where('level', ApprovalLevel::SuperAdmin)->where('decision', ApprovalDecision::Approved)->count(),
            'Rejected' => (clone $decisions)->where('decision', ApprovalDecision::Rejected)->count(),
            'Changes requested' => (clone $decisions)->where('decision', ApprovalDecision::ChangesRequested)->count(),
            'Average approval time' => $averageHours === null ? '—' : ($averageHours < 48 ? round($averageHours, 1).' hours' : round($averageHours / 24, 1).' days'),
        ];
    }

    /**
     * Figures and targets per travel salesperson (targets only for whole months).
     *
     * @return list<array<string, string|int|float>>
     */
    public function salespeople(): array
    {
        $people = User::query()->role(Role::TravelSalesperson->value)
            ->when($this->userId, fn (Builder $query) => $query->whereKey($this->userId))
            ->orderBy('name')->get(['id', 'name']);
        $actuals = TravelSalesMetrics::bySalesperson($this->from, $this->to, $people->modelKeys());
        $sameMonth = $this->from->isSameMonth($this->to) && $this->from->day === 1 && $this->to->isLastOfMonth();
        $targets = $sameMonth
            ? TravelTarget::query()->whereDate('month', $this->from->toDateString())->get()->groupBy('user_id')
            : collect();

        return $people->map(function (User $user) use ($actuals, $targets): array {
            $figures = $actuals[$user->id] ?? array_fill_keys(array_column(TravelTargetMetric::cases(), 'value'), 0.0);
            $target = fn (TravelTargetMetric $metric): string => (string) ($targets->get($user->id)?->firstWhere('metric', $metric)?->target_value ?? '');

            return [
                'Salesperson' => $user->name,
                'Flight bookings' => (int) $figures['flight_bookings'],
                'Flight target' => $target(TravelTargetMetric::FlightBookings),
                'Flight revenue (KES)' => $figures['flight_revenue'],
                'Tour bookings' => (int) $figures['tour_bookings'],
                'Tour target' => $target(TravelTargetMetric::TourBookings),
                'Tour revenue (KES)' => $figures['tour_revenue'],
                'Total travel sales (KES)' => $figures['flight_revenue'] + $figures['tour_revenue'],
            ];
        })->sortByDesc('Total travel sales (KES)')->values()->all();
    }

    /**
     * @param  Builder<FlightBooking>  $query
     * @return Builder<FlightBooking>
     */
    private function flights(Builder $query): Builder
    {
        return $query->when($this->userId, fn (Builder $query) => $query->where('salesperson_id', $this->userId));
    }

    /**
     * @param  Builder<PackageBooking>  $query
     * @return Builder<PackageBooking>
     */
    private function packages(Builder $query): Builder
    {
        return $query->when($this->userId, fn (Builder $query) => $query->where('package_bookings.salesperson_id', $this->userId));
    }
}
