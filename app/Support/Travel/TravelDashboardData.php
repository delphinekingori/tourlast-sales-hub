<?php

namespace App\Support\Travel;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Travel\ApprovalDecision;
use App\Enums\Travel\ApprovalLevel;
use App\Enums\Travel\CancellationStatus;
use App\Enums\Travel\ContractStatus;
use App\Enums\Travel\DepartureStatus;
use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\PackageVersionStatus;
use App\Enums\Travel\RefundStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Enums\Travel\TravelProviderStatus;
use App\Enums\Travel\TravelTargetMetric;
use App\Models\FlightBooking;
use App\Models\FollowUp;
use App\Models\Package;
use App\Models\PackageApproval;
use App\Models\PackageBooking;
use App\Models\PackageCancellation;
use App\Models\PackageDeparture;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Models\TravelRefund;
use App\Models\TravelTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Figures and action lists for the Travel dashboard. Scoped to one travel
 * salesperson, or to everyone (null) for Travel managers.
 */
class TravelDashboardData
{
    /** Flight refund statuses (lower-cased as the flights sync stores them) that are still open. */
    public const OpenFlightRefunds = ['pending', 'requested', 'processing', 'in_progress', 'submitted'];

    /** Flight refund statuses that mean the money went back. */
    public const DoneFlightRefunds = ['completed', 'refunded', 'paid', 'processed'];

    public function __construct(public ?int $userId) {}

    /**
     * @return array<string, float|int|null>
     */
    public function flightKpis(bool $withMarkup): array
    {
        $month = [now()->startOfMonth(), now()->endOfMonth()];
        $flights = fn (): Builder => FlightBooking::query()->when($this->userId, fn (Builder $query) => $query->where('salesperson_id', $this->userId));
        $sales = TravelSalesMetrics::flightSales(...$month)->when($this->userId, fn (Builder $query) => $query->where('salesperson_id', $this->userId));

        return [
            'today' => $flights()->whereDate('booked_at', today())->count(),
            'month' => (clone $sales)->count(),
            'upcoming' => $flights()->upcoming()->count(),
            'cancelled' => $flights()->whereBetween('cancelled_at', $month)->count(),
            'refunds_pending' => $flights()->whereIn('refund_status', self::OpenFlightRefunds)->count(),
            'refunds_completed' => $flights()->whereIn('refund_status', self::DoneFlightRefunds)->whereBetween('refund_completed_at', $month)->count(),
            'revenue' => (float) (clone $sales)->sum('total_amount'),
            'markup' => $withMarkup ? (float) (clone $sales)->sum('markup_amount') : null,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public function tourKpis(): array
    {
        $month = [now()->startOfMonth(), now()->endOfMonth()];
        $packages = fn (): Builder => Package::query()->current()->when($this->userId, fn (Builder $query) => $query->where('owner_id', $this->userId));
        $bookings = fn (): Builder => PackageBooking::query()->when($this->userId, fn (Builder $query) => $query->where('salesperson_id', $this->userId));
        $sold = TravelSalesMetrics::packageSales(...$month)->when($this->userId, fn (Builder $query) => $query->where('salesperson_id', $this->userId));

        return [
            'providers' => TravelProvider::query()->current()
                ->whereIn('status', [TravelProviderStatus::Active, TravelProviderStatus::Contracted])
                ->when($this->userId, fn (Builder $query) => $query->where('owner_id', $this->userId))
                ->count(),
            'published' => $packages()->where('status', PackageStatus::Published)->count(),
            'active' => $packages()->whereIn('status', [PackageStatus::Approved, PackageStatus::Published])->count(),
            'pending_approval' => $packages()->whereHas('workingVersion', fn (Builder $version) => $version->whereIn('status', [PackageVersionStatus::Submitted, PackageVersionStatus::SalesAdminApproved]))->count(),
            'bookings' => (clone $sold)->count(),
            'revenue' => (float) (clone $sold)->sum('amount_total'),
            'slots_sold' => (int) (clone $sold)->sum('travelers'),
            'upcoming_tours' => PackageDeparture::query()
                ->where('status', '!=', DepartureStatus::Cancelled)
                ->whereBetween('starts_on', [today()->toDateString(), today()->addDays(30)->toDateString()])
                ->when($this->userId, fn (Builder $query) => $query->whereHas('package', fn (Builder $package) => $package->where('owner_id', $this->userId)))
                ->count(),
            'pending_cancellations' => PackageCancellation::query()->where('status', CancellationStatus::Pending)
                ->whereHas('booking', fn (Builder $booking) => $booking->when($this->userId, fn (Builder $query) => $query->where('salesperson_id', $this->userId)))
                ->count(),
            'pending_refunds' => TravelRefund::query()->whereIn('status', [RefundStatus::Requested, RefundStatus::Approved, RefundStatus::Processing])
                ->whereHas('booking', fn (Builder $booking) => $booking->when($this->userId, fn (Builder $query) => $query->where('salesperson_id', $this->userId)))
                ->count(),
            'pending_bookings' => $bookings()->holdingSlots()->count(),
        ];
    }

    /**
     * Flight and tour sales for this month and the five before it.
     *
     * @return list<array{label: string, starts: string, flight_bookings: int, flight_revenue: float, tour_bookings: int, tour_revenue: float}>
     */
    public function salesTrend(): array
    {
        return TravelSalesMetrics::trend(now()->startOfMonth()->subMonthsNoOverflow(5), now()->endOfMonth(), $this->userId, 'month');
    }

    /**
     * Progress against this month's travel targets (one salesperson only).
     *
     * @return list<array{label: string, money: bool, actual: float, target: ?int}>
     */
    public function targets(): array
    {
        if (! $this->userId) {
            return [];
        }

        $actuals = TravelSalesMetrics::totals(now()->startOfMonth(), now()->endOfMonth(), $this->userId);
        $targets = TravelTarget::query()->where('user_id', $this->userId)->whereDate('month', now()->startOfMonth()->toDateString())->pluck('target_value', 'metric');

        return array_map(fn (TravelTargetMetric $metric): array => [
            'label' => $metric->label(),
            'money' => $metric->isMoney(),
            'actual' => $actuals[$metric->value],
            'target' => isset($targets[$metric->value]) ? (int) $targets[$metric->value] : null,
        ], TravelTargetMetric::cases());
    }

    /**
     * "My sales actions": what needs doing, most urgent first.
     *
     * @return Collection<int, array{when: string, tone: string, sort: string, label: string, detail: string, url: ?string, icon: string}>
     */
    public function salesActions(User $viewer, int $limit = 12): Collection
    {
        $actions = collect();
        $add = function (CarbonImmutable|\DateTimeInterface $at, string $label, string $detail, ?string $url, string $icon) use ($actions): void {
            $at = CarbonImmutable::instance($at);
            [$when, $tone] = match (true) {
                $at->lt(today()) => ['Overdue', 'danger'],
                $at->isToday() => ['Today', 'brand'],
                $at->isTomorrow() => ['Tomorrow', 'neutral'],
                default => [$at->format('D j M'), 'neutral'],
            };
            $actions->push(['when' => $when, 'tone' => $tone, 'sort' => $at->format('Y-m-d H:i'), 'label' => $label, 'detail' => $detail, 'url' => $url, 'icon' => $icon]);
        };
        $routeOr = fn (string $name, mixed $parameters = []): ?string => Route::has($name) ? route($name, $parameters) : null;

        FollowUp::query()->forTravel()->open()->with('subject')
            ->where('user_id', $viewer->id)
            ->where('due_at', '<=', now()->addDays(7)->endOfDay())
            ->chronological()->limit(20)->get()
            ->each(fn (FollowUp $item) => $add($item->due_at, $item->task, $item->type->label().' · '.$item->subjectLabel(), $item->subjectUrl(), $item->type->icon()));

        $mine = fn (Builder $query) => $query->when($this->userId, fn (Builder $query) => $query->where('owner_id', $this->userId));

        Package::query()->current()->tap($mine)
            ->whereHas('workingVersion', fn (Builder $version) => $version->whereIn('status', [PackageVersionStatus::Draft, PackageVersionStatus::ChangesRequested, PackageVersionStatus::Rejected]))
            ->with('workingVersion:id,status,updated_at')->limit(10)->get()
            ->each(fn (Package $package) => $add(
                today(),
                $package->workingVersion->status === PackageVersionStatus::Draft ? 'Submit package for approval' : 'Fix and resubmit package',
                $package->name.' · '.$package->workingVersion->status->label(),
                $routeOr('travel.packages.show', $package),
                'map',
            ));

        if ($viewer->can(Permission::ApprovePackagesFirst->value) || $viewer->can(Permission::ApprovePackagesFinal->value)) {
            $level = $viewer->hasRole(Role::SuperAdmin->value) ? PackageVersionStatus::SalesAdminApproved : PackageVersionStatus::Submitted;
            $waiting = Package::query()->current()->where('created_by', '!=', $viewer->id)
                ->whereHas('workingVersion', fn (Builder $version) => $version->where('status', $level))->count();

            if ($waiting > 0) {
                $add(today(), "Review {$waiting} ".str('package')->plural($waiting).' awaiting your approval', $level->label(), $routeOr('travel.approvals.index'), 'shield');
            }
        }

        ProviderContract::query()->where('status', ContractStatus::Active)
            ->whereBetween('ends_on', [today()->subDays(7)->toDateString(), today()->addDays(30)->toDateString()])
            ->whereHas('provider', $mine)
            ->with('provider:id,name')->limit(10)->get()
            ->each(fn (ProviderContract $contract) => $add(
                $contract->ends_on->subDays(14)->max(today()),
                $contract->ends_on->isPast() ? 'Renew expired contract' : 'Provider contract renewal',
                $contract->provider->name.' · ends '.$contract->ends_on->format('j M Y'),
                $routeOr('travel.contracts.show', $contract),
                'document',
            ));

        PackageBooking::query()->holdingSlots()
            ->when($this->userId, fn (Builder $query) => $query->where('salesperson_id', $this->userId))
            ->with(['client:id,name', 'package:id,name'])->orderBy('hold_expires_at')->limit(10)->get()
            ->each(fn (PackageBooking $booking) => $add(
                $booking->hold_expires_at ?? today(),
                'Confirm customer booking',
                $booking->reference.' · '.$booking->client?->name.' · '.$booking->package?->name,
                $routeOr('travel.bookings.show', $booking),
                'ticket',
            ));

        PackageBooking::query()->where('status', TravelBookingStatus::Confirmed)
            ->when($this->userId, fn (Builder $query) => $query->where('salesperson_id', $this->userId))
            ->whereHas('departure', fn (Builder $departure) => $departure->whereBetween('starts_on', [today()->toDateString(), today()->addDays(7)->toDateString()]))
            ->with(['client:id,name', 'departure:id,starts_on'])->limit(10)->get()
            ->each(fn (PackageBooking $booking) => $add(
                $booking->departure->starts_on->subDays(2)->max(today()),
                'Customer pre-trip confirmation',
                $booking->reference.' · '.$booking->client?->name.' · departs '.$booking->departure->starts_on->format('D j M'),
                $routeOr('travel.bookings.show', $booking),
                'clipboard',
            ));

        return $actions->sortBy('sort')->values()->take($limit);
    }

    /**
     * Upcoming departures that are nearly full or full.
     *
     * @return Collection<int, PackageDeparture>
     */
    public function lowAvailability(int $limit = 6): Collection
    {
        return PackageDeparture::query()
            ->with('package:id,name,owner_id')
            ->withSlotCounts()
            ->whereIn('status', [DepartureStatus::Open, DepartureStatus::NearlyFull, DepartureStatus::Full])
            ->where('starts_on', '>=', today()->toDateString())
            ->when($this->userId, fn (Builder $query) => $query->whereHas('package', fn (Builder $package) => $package->where('owner_id', $this->userId)))
            ->orderBy('starts_on')
            ->limit(60)
            ->get()
            ->filter(fn (PackageDeparture $departure) => in_array($departure->availabilityStatus(), [DepartureStatus::NearlyFull, DepartureStatus::Full], true))
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, ProviderContract>
     */
    public function expiringContracts(int $limit = 6): Collection
    {
        return ProviderContract::query()
            ->with('provider:id,name,owner_id')
            ->where('status', ContractStatus::Active)
            ->whereNotNull('ends_on')
            ->whereDate('ends_on', '<=', today()->addDays(ProviderContract::ExpiringSoonDays))
            ->when($this->userId, fn (Builder $query) => $query->whereHas('provider', fn (Builder $provider) => $provider->where('owner_id', $this->userId)))
            ->orderBy('ends_on')
            ->limit($limit)
            ->get();
    }

    /**
     * Approvals this month (managers' view).
     *
     * @return array{pending: int, approved: int, rejected: int}
     */
    public function approvals(): array
    {
        $month = [now()->startOfMonth(), now()->endOfMonth()];

        return [
            'pending' => Package::query()->current()->whereHas('workingVersion', fn (Builder $version) => $version->whereIn('status', [PackageVersionStatus::Submitted, PackageVersionStatus::SalesAdminApproved]))->count(),
            'approved' => PackageApproval::query()->where('level', ApprovalLevel::SuperAdmin)->where('decision', ApprovalDecision::Approved)->whereBetween('decided_at', $month)->count(),
            'rejected' => PackageApproval::query()->whereIn('decision', [ApprovalDecision::Rejected, ApprovalDecision::ChangesRequested])->whereBetween('decided_at', $month)->count(),
        ];
    }

    /**
     * Travel salespeople this month (managers' view).
     *
     * @return Collection<int, array{user: User, flight_bookings: float, flight_revenue: float, tour_bookings: float, tour_revenue: float, total: float, packages_created: int}>
     */
    public static function salespeople(): Collection
    {
        $people = User::query()->active()->role(Role::TravelSalesperson->value)->orderBy('name')->get();
        $actuals = TravelSalesMetrics::bySalesperson(now()->startOfMonth(), now()->endOfMonth(), $people->modelKeys());
        $created = Package::query()->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->selectRaw('created_by, count(*) as total')->groupBy('created_by')->pluck('total', 'created_by');

        return $people->map(function (User $user) use ($actuals, $created): array {
            $figures = $actuals[$user->id] ?? array_fill_keys(array_column(TravelTargetMetric::cases(), 'value'), 0.0);

            return [
                'user' => $user,
                'flight_bookings' => $figures['flight_bookings'],
                'flight_revenue' => $figures['flight_revenue'],
                'tour_bookings' => $figures['tour_bookings'],
                'tour_revenue' => $figures['tour_revenue'],
                'total' => $figures['flight_revenue'] + $figures['tour_revenue'],
                'packages_created' => (int) ($created[$user->id] ?? 0),
            ];
        })->sortByDesc('total')->values();
    }
}
