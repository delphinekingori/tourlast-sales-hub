<?php

namespace App\Actions\Travel\Departures;

use App\Actions\Travel\Resources\AssignTripResources;
use App\Enums\Travel\DepartureStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Enums\Travel\TripStatus;
use App\Models\Package;
use App\Models\PackageDeparture;
use App\Models\User;
use App\Support\Audit;
use App\Support\Travel\DepartureAlerts;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a dated departure (package inventory). Only the package
 * owner or a Travel manager may do this, only on a package with an approved
 * live version, and capacity can never drop below the slots already taken.
 * Overbooking can only be allowed by a Travel manager.
 */
class SaveDeparture
{
    public function __construct(private AssignTripResources $assign) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, ?PackageDeparture $departure = null): PackageDeparture
    {
        $package = $departure?->package ?? Package::query()->find((int) ($input['package_id'] ?? 0));

        if (! $package) {
            throw ValidationException::withMessages(['package_id' => 'Choose a package.']);
        }

        TravelAccess::abortUnlessCanChange($actor, $package->owner_id);

        if (! $package->isSellable()) {
            throw ValidationException::withMessages(['package_id' => 'Departures can only be added to an approved package.']);
        }

        $data = Validator::make($input, [
            'starts_on' => array_filter(['required', 'date', $departure ? null : 'after_or_equal:today']),
            'start_time' => ['nullable', 'date_format:H:i'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'capacity' => ['required', 'integer', 'min:1', 'max:5000'],
            'waitlist_count' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'status' => ['required', Rule::in([DepartureStatus::Open->value, DepartureStatus::Closed->value, DepartureStatus::Cancelled->value])],
            'trip_status' => ['required', Rule::enum(TripStatus::class)],
            'driver_id' => ['nullable', 'integer', Rule::exists('drivers', 'id')],
            'guide_id' => ['nullable', 'integer', Rule::exists('guides', 'id')],
            'allow_overbooking' => ['boolean'],
            'override_conflict' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $allowOverbooking = (bool) ($data['allow_overbooking'] ?? false);

        if ($allowOverbooking !== (bool) $departure?->allow_overbooking && ! TravelAccess::managesAll($actor)) {
            abort(403, 'Only a Sales Admin or Super Admin can allow overbooking.');
        }

        return DB::transaction(function () use ($actor, $package, $departure, $data, $allowOverbooking): PackageDeparture {
            $isNew = $departure === null;
            $departure = $departure
                ? PackageDeparture::query()->lockForUpdate()->findOrFail($departure->id)
                : new PackageDeparture(['package_id' => $package->id, 'created_by' => $actor->id]);

            if (! $isNew) {
                $taken = $departure->soldSlots() + $departure->reservedSlots();

                if ((int) $data['capacity'] < $taken && ! $allowOverbooking) {
                    throw ValidationException::withMessages(['capacity' => "Capacity cannot be lower than the {$taken} slots already sold or reserved."]);
                }

                if ($data['status'] === DepartureStatus::Cancelled->value && $departure->status !== DepartureStatus::Cancelled
                    && $departure->bookings()->whereIn('status', [TravelBookingStatus::Pending, TravelBookingStatus::Confirmed])->exists()) {
                    throw ValidationException::withMessages(['status' => 'Cancel or move the bookings on this departure before cancelling it.']);
                }
            }

            $before = $departure->only(['starts_on', 'ends_on', 'capacity', 'status', 'trip_status', 'allow_overbooking']);

            $departure->fill([
                'starts_on' => $data['starts_on'],
                'start_time' => $data['start_time'] ?? null,
                'ends_on' => $data['ends_on'],
                'end_time' => $data['end_time'] ?? null,
                'capacity' => (int) $data['capacity'],
                'waitlist_count' => (int) ($data['waitlist_count'] ?? 0),
                'status' => $data['status'],
                'trip_status' => $data['trip_status'],
                'allow_overbooking' => $allowOverbooking,
                'overbooking_approved_by' => $allowOverbooking ? ($departure->allow_overbooking ? $departure->overbooking_approved_by : $actor->id) : null,
                'notes' => $data['notes'] ?? null,
            ])->save();

            $this->assign->forDeparture(
                $actor,
                $departure,
                isset($data['driver_id']) ? (int) $data['driver_id'] : null,
                isset($data['guide_id']) ? (int) $data['guide_id'] : null,
                (bool) ($data['override_conflict'] ?? false),
            );

            Audit::record(
                $departure,
                $isNew ? 'departure.created' : 'departure.updated',
                ($isNew ? 'Departure added: ' : 'Departure changed: ').$package->name.' '.$departure->dateLabel(),
                $isNew ? [] : Audit::diff($before, $departure->only(array_keys($before))),
            );

            DepartureAlerts::check($departure);

            return $departure;
        });
    }
}
