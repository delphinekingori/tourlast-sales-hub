<?php

namespace App\Support\Travel;

use App\Enums\Travel\DepartureStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\PackageDeparture;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Driver and guide scheduling: which departures a driver or guide is already
 * doing, so overlapping trips can be caught before they are assigned.
 *
 * A resource is on a departure when it is assigned to the departure itself,
 * to the package with no departure-level override, or to a live booking on
 * the departure.
 */
class ResourceSchedule
{
    /**
     * Departures overlapping $from–$to that the driver or guide is already on.
     *
     * @return Collection<int, PackageDeparture>
     */
    public static function conflictsFor(Driver|Guide $resource, string $from, string $to, ?int $ignoreDepartureId = null): Collection
    {
        return self::assignedTo($resource)
            ->overlapping($from, $to)
            ->when($ignoreDepartureId, fn (Builder $query) => $query->whereKeyNot($ignoreDepartureId))
            ->with('package:id,name')
            ->orderBy('starts_on')
            ->get();
    }

    /**
     * The resource's departures from today on, for "Oct 12 — Masai Mara" lists.
     *
     * @return Collection<int, PackageDeparture>
     */
    public static function upcomingFor(Driver|Guide $resource, int $limit = 6): Collection
    {
        return self::assignedTo($resource)
            ->whereDate('ends_on', '>=', today())
            ->with('package:id,name')
            ->orderBy('starts_on')
            ->limit($limit)
            ->get();
    }

    /**
     * One line per clash, for validation messages.
     *
     * @param  Collection<int, PackageDeparture>  $conflicts
     */
    public static function describe(Driver|Guide $resource, Collection $conflicts): string
    {
        return $resource->name.' is already assigned: '.$conflicts
            ->map(fn (PackageDeparture $departure): string => $departure->starts_on->format('M j').' — '.$departure->package?->name)
            ->implode('; ').'.';
    }

    /**
     * @return Builder<PackageDeparture>
     */
    private static function assignedTo(Driver|Guide $resource): Builder
    {
        $column = $resource instanceof Driver ? 'driver_id' : 'guide_id';

        return PackageDeparture::query()
            ->where('status', '!=', DepartureStatus::Cancelled)
            ->where(fn (Builder $query) => $query
                ->where($column, $resource->id)
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull($column)
                    ->whereHas('package', fn (Builder $package) => $package->where($column, $resource->id)))
                ->orWhereHas('bookings', fn (Builder $bookings) => $bookings
                    ->where($column, $resource->id)
                    ->whereIn('status', [TravelBookingStatus::Pending, TravelBookingStatus::Confirmed, TravelBookingStatus::Completed])));
    }
}
