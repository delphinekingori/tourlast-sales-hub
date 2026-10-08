<?php

namespace App\Actions\Travel\Bookings;

use App\Actions\Travel\RefreshBookingPayment;
use App\Enums\Permission;
use App\Enums\Travel\BookingSource;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\PackageDeparture;
use App\Models\TravelClient;
use App\Models\User;
use App\Support\Alerts;
use App\Support\Audit;
use App\Support\Travel\DepartureAlerts;
use App\Support\Travel\InfluencerCodes;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Takes a booking on a package departure. The departure row is locked while
 * the slots are recounted, so two people can never sell the last slot twice;
 * a departure only takes more travelers than it has room for when a Travel
 * manager has allowed overbooking on it. The price comes from the approved
 * live version (a Travel manager may override it with a reason).
 *
 * "guests" lists each traveler (see SaveBookingGuests) and must match the
 * adults, children and infants. The Hub form always sends it; API callers
 * that leave it out get a booking with no guest details yet.
 */
class CreatePackageBooking
{
    public function __construct(private RefreshBookingPayment $refresh) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input): PackageBooking
    {
        TravelAccess::abortUnlessWorks($actor);
        $manages = TravelAccess::managesAll($actor);

        $data = Validator::make($input, [
            'package_id' => ['required', 'integer', Rule::exists('packages', 'id')],
            'package_departure_id' => ['required', 'integer', Rule::exists('package_departures', 'id')],
            'travel_client_id' => ['nullable', 'integer', Rule::exists('travel_clients', 'id')],
            'client_name' => ['required_without:travel_client_id', 'nullable', 'string', 'max:120'],
            'client_email' => ['nullable', 'email', 'max:160'],
            'client_phone' => ['nullable', 'string', 'max:30'],
            'client_country' => ['nullable', 'string', 'max:60'],
            'adults' => ['required', 'integer', 'min:1', 'max:500'],
            'children' => ['nullable', 'integer', 'min:0', 'max:500'],
            'infants' => ['nullable', 'integer', 'min:0', 'max:500'],
            'special_requirements' => ['nullable', 'string', 'max:2000'],
            'dietary_requirements' => ['nullable', 'string', 'max:1000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'influencer_code' => ['nullable', 'string', 'max:30'],
            'salesperson_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'amount_override' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'override_reason' => ['nullable', 'required_with:amount_override', 'string', 'max:500'],
        ], [
            'client_name.required_without' => 'Choose an existing client or enter the client’s name.',
            'override_reason.required_with' => 'Give a reason for changing the price.',
        ])->validate();

        if (empty($data['travel_client_id']) && blank($data['client_phone'] ?? null) && blank($data['client_email'] ?? null)) {
            throw ValidationException::withMessages(['client_phone' => 'Enter the client’s phone or email so they are not added twice.']);
        }

        $package = Package::query()->with('liveVersion')->findOrFail($data['package_id']);

        if (! $package->isSellable() || ! $package->liveVersion) {
            throw ValidationException::withMessages(['package_id' => 'This package has no approved version that can be sold.']);
        }

        $version = $package->liveVersion;
        $adults = (int) $data['adults'];
        $children = (int) ($data['children'] ?? 0);
        $infants = (int) ($data['infants'] ?? 0);
        $travelers = $adults + $children + $infants;

        if ($version->max_travelers && $travelers > $version->max_travelers) {
            throw ValidationException::withMessages(['adults' => "This package takes at most {$version->max_travelers} travelers per booking."]);
        }

        if ($travelers < $version->min_travelers) {
            throw ValidationException::withMessages(['adults' => "This package needs at least {$version->min_travelers} travelers."]);
        }

        $guests = isset($input['guests']) ? SaveBookingGuests::validated($input['guests'], $adults, $children, $infants) : null;

        $code = null;

        if (filled($data['influencer_code'] ?? null)) {
            $code = InfluencerCodes::resolve($data['influencer_code'], 'packages', today());

            if (! $code) {
                throw ValidationException::withMessages(['influencer_code' => 'Code not valid for this booking.']);
            }
        }

        $salespersonId = $actor->id;

        if (! empty($data['salesperson_id']) && (int) $data['salesperson_id'] !== $actor->id) {
            abort_unless($manages, 403, 'Only a Travel manager can book on behalf of someone else.');
            $salesperson = User::query()->findOrFail($data['salesperson_id']);

            if (! $salesperson->can(Permission::AccessTravelSales->value)) {
                throw ValidationException::withMessages(['salesperson_id' => 'Choose someone who works in Travel Sales.']);
            }

            $salespersonId = $salesperson->id;
        }

        $price = $version->priceFor($adults, $children, $infants);
        $amount = $price;

        if (isset($data['amount_override']) && $data['amount_override'] !== null && $data['amount_override'] !== '') {
            abort_unless($manages, 403, 'Only a Travel manager can change the price of a booking.');
            $amount = round((float) $data['amount_override'], 2);
        }

        $booking = DB::transaction(function () use ($actor, $data, $package, $version, $adults, $children, $infants, $travelers, $guests, $code, $salespersonId, $amount): PackageBooking {
            $departure = PackageDeparture::query()->lockForUpdate()->findOrFail($data['package_departure_id']);

            if ($departure->package_id !== $package->id) {
                throw ValidationException::withMessages(['package_departure_id' => 'Choose a departure of this package.']);
            }

            if (! $departure->isBookable()) {
                throw ValidationException::withMessages(['package_departure_id' => 'This departure is not taking bookings.']);
            }

            $available = $departure->availableSlots();

            if ($travelers > $available && ! $departure->allow_overbooking) {
                throw ValidationException::withMessages(['adults' => $available === 0
                    ? 'This departure is fully booked.'
                    : "Only {$available} ".($available === 1 ? 'slot is' : 'slots are').' left on this departure.']);
            }

            $client = ! empty($data['travel_client_id'])
                ? TravelClient::query()->findOrFail($data['travel_client_id'])
                : TravelClient::findOrCreateFor([
                    'name' => $data['client_name'],
                    'email' => $data['client_email'] ?? null,
                    'phone' => $data['client_phone'] ?? null,
                    'country' => $data['client_country'] ?? null,
                ], $actor->id);

            $booking = PackageBooking::query()->create([
                'reference' => PackageBooking::nextReference(),
                'package_id' => $package->id,
                'package_version_id' => $version->id,
                'package_departure_id' => $departure->id,
                'travel_client_id' => $client->id,
                'salesperson_id' => $salespersonId,
                'influencer_code_id' => $code?->id,
                'source' => BookingSource::Manual,
                'adults' => $adults,
                'children' => $children,
                'infants' => $infants,
                'currency' => $version->currency,
                'amount_total' => $amount,
                'status' => TravelBookingStatus::Pending,
                'special_requirements' => $data['special_requirements'] ?? null,
                'dietary_requirements' => $data['dietary_requirements'] ?? null,
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                'notes' => $data['notes'] ?? null,
                'hold_expires_at' => now()->addHours((int) config('travel.reservation_hold_hours')),
                'created_by' => $actor->id,
            ]);

            if ($guests !== null) {
                SaveBookingGuests::write($booking, $guests);
            }

            return $booking;
        });

        $this->refresh->handle($booking);

        Audit::record($booking, 'booking.created', 'Booking '.$booking->reference.' taken: '.$travelers.' '.($travelers === 1 ? 'traveler' : 'travelers').' on '.$package->name);

        if (round($amount, 2) !== round($price, 2)) {
            Audit::record($booking, 'booking.price_overridden', 'Price changed on '.$booking->reference.': '.$data['override_reason'], ['amount_total' => [$price, $amount]]);
        }

        $booking->load(['client', 'departure']);
        Alerts::sendTravel(
            'travel_booking_new',
            'New package booking',
            "{$booking->client->name} booked {$package->name} ({$booking->departure->dateLabel()}), {$travelers} ".($travelers === 1 ? 'traveler' : 'travelers').'.',
            route('travel.bookings.show', $booking),
            $package->owner_id !== $actor->id ? $package->owner : null,
        );

        DepartureAlerts::check($booking->departure);

        return $booking;
    }
}
