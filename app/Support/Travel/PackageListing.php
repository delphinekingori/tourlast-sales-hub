<?php

namespace App\Support\Travel;

use App\Enums\Travel\ContractStatus;
use App\Enums\Travel\DepartureStatus;
use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\PackageVersionStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\Package;
use App\Models\PackageDeparture;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server-side query for the packages table: eager loads, slot totals for
 * future departures (one query, no N+1) and the filters.
 */
class PackageListing
{
    public const ApprovalFilters = [
        'awaiting_me' => 'Awaiting my review',
        'awaiting_sales_admin' => 'Awaiting Sales Admin',
        'awaiting_super_admin' => 'Awaiting Super Admin',
        'changes' => 'Changes requested / rejected',
        'approval_required' => 'Approval required (changed live package)',
    ];

    /**
     * @param  array{search?: string, status?: string, approval?: string, provider?: string, destination?: string, owner?: string, contract?: string}  $filters
     * @return Builder<Package>
     */
    public static function query(User $viewer, array $filters): Builder
    {
        $status = PackageStatus::tryFrom((string) ($filters['status'] ?? ''));

        return Package::query()
            ->with([
                'provider:id,name',
                'owner:id,name',
                'creator:id,name',
                'contract:id,travel_provider_id,contract_number,status,starts_on,ends_on',
                'liveVersion:id,package_id,major,minor,status,adult_price,currency',
                'workingVersion:id,package_id,major,minor,status,adult_price,currency,material_changes',
            ])
            ->withMin(['departures as next_departure_on' => fn (Builder $query) => self::future($query)], 'starts_on')
            ->withSum(['departures as future_capacity' => fn (Builder $query) => self::future($query)], 'capacity')
            ->withSum(['bookings as future_sold' => fn (Builder $query) => $query
                ->whereIn('status', [TravelBookingStatus::Confirmed, TravelBookingStatus::Completed])
                ->whereHas('departure', fn (Builder $departure) => self::future($departure))], 'travelers')
            ->withSum(['bookings as future_reserved' => fn (Builder $query) => $query
                ->holdingSlots()
                ->whereHas('departure', fn (Builder $departure) => self::future($departure))], 'travelers')
            ->when($status === PackageStatus::Archived, fn (Builder $query) => $query->whereNotNull('archived_at'), fn (Builder $query) => $query->whereNull('archived_at'))
            ->when($status && $status !== PackageStatus::Archived, fn (Builder $query) => $query->where('status', $status))
            ->search((string) ($filters['search'] ?? ''))
            ->when(filled($filters['provider'] ?? null), fn (Builder $query) => $query->where('travel_provider_id', (int) $filters['provider']))
            ->when(filled($filters['destination'] ?? null), fn (Builder $query) => $query->where('destination', $filters['destination']))
            ->when(filled($filters['owner'] ?? null) && TravelAccess::managesAll($viewer), fn (Builder $query) => $query->where('owner_id', (int) $filters['owner']))
            ->when(($filters['owner'] ?? '') === 'mine', fn (Builder $query) => $query->where('owner_id', $viewer->id))
            ->when(($filters['contract'] ?? '') === 'problem', fn (Builder $query) => self::contractProblem($query))
            ->when(filled($filters['approval'] ?? null), fn (Builder $query) => self::approval($query, (string) $filters['approval'], $viewer));
    }

    /**
     * Packages with no contract in force.
     *
     * @param  Builder<Package>  $query
     */
    public static function contractProblem(Builder $query): void
    {
        $query->whereDoesntHave('contract', fn (Builder $contract) => $contract
            ->where('status', ContractStatus::Active)
            ->whereDate('starts_on', '<=', today())
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', today())));
    }

    /**
     * @param  Builder<Package>  $query
     */
    public static function approval(Builder $query, string $filter, User $viewer): void
    {
        match ($filter) {
            'awaiting_me' => $query->whereNotIn('owner_id', [$viewer->id])->where('created_by', '!=', $viewer->id)
                ->whereHas('workingVersion', fn (Builder $version) => $version->whereIn('status', self::reviewableStatuses($viewer))),
            'awaiting_sales_admin' => $query->whereHas('workingVersion', fn (Builder $version) => $version->where('status', PackageVersionStatus::Submitted)),
            'awaiting_super_admin' => $query->whereHas('workingVersion', fn (Builder $version) => $version->where('status', PackageVersionStatus::SalesAdminApproved)),
            'changes' => $query->whereHas('workingVersion', fn (Builder $version) => $version->whereIn('status', [PackageVersionStatus::ChangesRequested, PackageVersionStatus::Rejected])),
            'approval_required' => $query->whereNotNull('live_version_id')->whereHas('workingVersion', fn (Builder $version) => $version->whereNotNull('material_changes')),
            default => null,
        };
    }

    /**
     * Version statuses the viewer reviews.
     *
     * @return list<PackageVersionStatus>
     */
    public static function reviewableStatuses(User $viewer): array
    {
        return array_values(array_filter([
            $viewer->can('approve-packages-first') ? PackageVersionStatus::Submitted : null,
            $viewer->can('approve-packages-final') ? PackageVersionStatus::SalesAdminApproved : null,
        ]));
    }

    /**
     * Upcoming departures that are not cancelled or closed.
     *
     * @param  Builder<PackageDeparture>  $query
     */
    public static function future(Builder $query): void
    {
        $query->whereDate('starts_on', '>=', today())->whereNotIn('status', [DepartureStatus::Cancelled, DepartureStatus::Closed]);
    }

    /**
     * @return array{drafts: int, pending: int, approved: int, published: int, approval_required: int, low_availability: int}
     */
    public static function summary(): array
    {
        $base = Package::query()->whereNull('archived_at');
        $ratio = (float) config('travel.nearly_full_ratio');

        return [
            'drafts' => (clone $base)->where('status', PackageStatus::Draft)->count(),
            'pending' => (clone $base)->whereHas('workingVersion', fn (Builder $version) => $version->whereIn('status', [PackageVersionStatus::Submitted, PackageVersionStatus::SalesAdminApproved]))->count(),
            'approved' => (clone $base)->whereIn('status', [PackageStatus::Approved, PackageStatus::Unpublished])->count(),
            'published' => (clone $base)->where('status', PackageStatus::Published)->count(),
            'approval_required' => (clone $base)->whereNotNull('live_version_id')->whereHas('workingVersion', fn (Builder $version) => $version->whereNotNull('material_changes'))->count(),
            'low_availability' => (clone $base)->whereHas('departures', fn (Builder $departure) => $departure
                ->whereDate('starts_on', '>=', today())
                ->where('status', DepartureStatus::Open)
                ->whereRaw('(select coalesce(sum(travelers), 0) from package_bookings where package_bookings.package_departure_id = package_departures.id and package_bookings.status in (?, ?)) >= package_departures.capacity * ?', [
                    TravelBookingStatus::Confirmed->value, TravelBookingStatus::Completed->value, $ratio,
                ]))->count(),
        ];
    }
}
