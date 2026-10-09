<?php

namespace App\Actions\Travel\Resources;

use App\Enums\Travel\ResourceStatus;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\PackageBooking;
use App\Models\PackageDeparture;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\ResourceSchedule;
use App\Support\Travel\TravelAccess;
use Illuminate\Validation\ValidationException;

/**
 * Assigns a driver and guide to a departure or a single booking. A driver or
 * guide already on an overlapping trip is refused unless a Travel manager
 * overrides the clash (the override is audited). Inactive resources can't
 * be assigned.
 */
class AssignTripResources
{
    public function forDeparture(User $actor, PackageDeparture $departure, ?int $driverId, ?int $guideId, bool $override = false): void
    {
        TravelAccess::abortUnlessCanChange($actor, $departure->package->owner_id);

        $changes = [];
        $from = $departure->starts_on->toDateString();
        $to = $departure->ends_on->toDateString();

        foreach (['driver_id' => [Driver::class, $driverId], 'guide_id' => [Guide::class, $guideId]] as $column => [$class, $id]) {
            if ($departure->{$column} === $id) {
                continue;
            }

            if ($id !== null) {
                $resource = $this->resolve($class, $id, $column);
                $this->guardConflicts($actor, $resource, $from, $to, $departure->id, $override, $column, $departure);
            }

            $changes[$column] = [$departure->{$column}, $id];
            $departure->{$column} = $id;
        }

        if ($changes !== []) {
            $departure->save();
            Audit::record($departure, 'departure.resources_changed', 'Driver/guide changed for '.$departure->package->name.' '.$departure->dateLabel(), $changes);
        }
    }

    public function forBooking(User $actor, PackageBooking $booking, ?int $driverId, ?int $guideId, bool $override = false): void
    {
        abort_unless($booking->isWorkableBy($actor), 403);

        $departure = $booking->departure;
        $changes = [];
        $from = $departure->starts_on->toDateString();
        $to = $departure->ends_on->toDateString();

        foreach (['driver_id' => [Driver::class, $driverId], 'guide_id' => [Guide::class, $guideId]] as $column => [$class, $id]) {
            if ($booking->{$column} === $id) {
                continue;
            }

            if ($id !== null) {
                $resource = $this->resolve($class, $id, $column);
                $this->guardConflicts($actor, $resource, $from, $to, $departure->id, $override, $column, $departure);
            }

            $changes[$column] = [$booking->{$column}, $id];
            $booking->{$column} = $id;
        }

        if ($changes !== []) {
            $booking->save();
            Audit::record($booking, 'booking.resources_changed', 'Driver/guide changed on booking '.$booking->reference, $changes);
        }
    }

    /**
     * @param  class-string<Driver|Guide>  $class
     */
    private function resolve(string $class, int $id, string $column): Driver|Guide
    {
        $resource = $class::query()->findOrFail($id);

        if ($resource->status !== ResourceStatus::Active) {
            throw ValidationException::withMessages([$column => $resource->name.' is not available for assignment.']);
        }

        return $resource;
    }

    private function guardConflicts(User $actor, Driver|Guide $resource, string $from, string $to, int $departureId, bool $override, string $column, PackageDeparture $departure): void
    {
        $conflicts = ResourceSchedule::conflictsFor($resource, $from, $to, $departureId);

        if ($conflicts->isEmpty()) {
            return;
        }

        if (! $override || ! TravelAccess::managesAll($actor)) {
            throw ValidationException::withMessages([$column => ResourceSchedule::describe($resource, $conflicts)]);
        }

        Audit::record($departure, 'resource.conflict_overridden', 'Schedule clash overridden for '.$resource->name.' on '.$departure->package->name.' '.$departure->dateLabel(), [
            'clashes_with' => [null, $conflicts->pluck('id')->all()],
        ]);
    }
}
