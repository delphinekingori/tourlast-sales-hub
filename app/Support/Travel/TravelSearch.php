<?php

namespace App\Support\Travel;

use App\Enums\Travel\TravelBookingStatus;
use App\Models\Driver;
use App\Models\FlightBooking;
use App\Models\Guide;
use App\Models\Influencer;
use App\Models\InfluencerCode;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\ProviderContract;
use App\Models\TravelClient;
use App\Models\TravelPayment;
use App\Models\TravelProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

/**
 * One search across Travel Sales: flights, providers (with their contracts,
 * packages and bookings), packages, bookings, clients, payments, drivers,
 * guides and influencer codes. Every group only returns what the person may
 * open; Accounts get bookings, clients and payments only.
 */
class TravelSearch
{
    public const PerGroup = 8;

    public function __construct(private User $user) {}

    public static function canUse(User $user): bool
    {
        return TravelAccess::works($user) || TravelAccess::handlesPayments($user);
    }

    /**
     * @return list<array{key: string, label: string, icon: string, total: int, more: ?string, items: list<array{title: string, meta: string, url: ?string, badge: ?string, tone: string}>}>
     */
    public function run(string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $works = TravelAccess::works($this->user);

        $groups = $works ? [
            $this->providers($term),
            $this->contracts($term),
            $this->packages($term),
            $this->bookings($term),
            $this->clients($term),
            $this->payments($term),
            $this->flights($term),
            $this->drivers($term),
            $this->guides($term),
            $this->influencers($term),
        ] : [
            $this->bookings($term),
            $this->clients($term),
            $this->payments($term),
        ];

        return array_values(array_filter($groups, fn (array $group) => $group['total'] > 0));
    }

    /**
     * @return array{key: string, label: string, icon: string, total: int, more: ?string, items: list<array<string, mixed>>}
     */
    private function providers(string $term): array
    {
        $query = TravelProvider::query()->current()->search($term);

        $items = (clone $query)->with('owner:id,name')
            ->withCount(['contracts', 'packages', 'packages as bookings_count' => fn (Builder $packages) => $packages->join('package_bookings', 'package_bookings.package_id', '=', 'packages.id')
                ->whereIn('package_bookings.status', [TravelBookingStatus::Confirmed, TravelBookingStatus::Completed])])
            ->orderBy('name')->limit(self::PerGroup)->get()
            ->map(fn (TravelProvider $provider) => [
                'title' => $provider->name,
                'meta' => collect([
                    $provider->provider_type->label(),
                    $provider->city,
                    $provider->contracts_count.' '.str('contract')->plural($provider->contracts_count),
                    $provider->packages_count.' '.str('package')->plural($provider->packages_count),
                    $provider->bookings_count.' '.str('booking')->plural($provider->bookings_count),
                    $provider->owner?->name,
                ])->filter()->implode(' · '),
                'url' => $this->route('travel.providers.show', $provider),
                'badge' => $provider->status->label(),
                'tone' => $provider->status->tone(),
            ])->all();

        return $this->group('providers', 'Providers', 'building', (clone $query)->count(), 'travel.providers.index', $term, $items);
    }

    private function contracts(string $term): array
    {
        $like = '%'.$term.'%';
        $query = ProviderContract::query()->where(fn (Builder $query) => $query
            ->where('contract_number', 'like', $like)
            ->orWhereHas('provider', fn (Builder $provider) => $provider->search($term)));

        $items = (clone $query)->with('provider:id,name')->latest('starts_on')->limit(self::PerGroup)->get()
            ->map(fn (ProviderContract $contract) => [
                'title' => $contract->contract_number.' · '.$contract->provider?->name,
                'meta' => collect([$contract->contract_type, $contract->ends_on ? 'ends '.$contract->ends_on->format('j M Y') : 'open-ended'])->filter()->implode(' · '),
                'url' => $this->route('travel.contracts.show', $contract),
                'badge' => $contract->effectiveStatus()->label(),
                'tone' => $contract->effectiveStatus()->tone(),
            ])->all();

        return $this->group('contracts', 'Contracts', 'document', (clone $query)->count(), 'travel.contracts.index', $term, $items);
    }

    private function packages(string $term): array
    {
        $query = Package::query()->current()->search($term);

        $items = (clone $query)->with(['provider:id,name', 'owner:id,name'])->orderBy('name')->limit(self::PerGroup)->get()
            ->map(fn (Package $package) => [
                'title' => $package->name,
                'meta' => collect([$package->reference, $package->provider?->name, $package->destination, $package->owner?->name])->filter()->implode(' · '),
                'url' => $this->route('travel.packages.show', $package),
                'badge' => $package->status->label(),
                'tone' => $package->status->tone(),
            ])->all();

        return $this->group('packages', 'Packages', 'map', (clone $query)->count(), 'travel.packages.index', $term, $items);
    }

    private function bookings(string $term): array
    {
        $query = PackageBooking::query()->visibleTo($this->user)
            ->where(fn (Builder $query) => $query->search($term)
                ->orWhereHas('package.provider', fn (Builder $provider) => $provider->search($term)));

        $items = (clone $query)->with(['client:id,name', 'package:id,name', 'departure:id,starts_on'])->latest()->limit(self::PerGroup)->get()
            ->map(fn (PackageBooking $booking) => [
                'title' => $booking->reference.' · '.$booking->client?->name,
                'meta' => collect([$booking->package?->name, $booking->departure?->starts_on?->format('j M Y'), $booking->travelers.' '.str('traveler')->plural($booking->travelers), 'KES '.number_format((float) $booking->amount_total)])->filter()->implode(' · '),
                'url' => $this->route('travel.bookings.show', $booking),
                'badge' => $booking->status->label(),
                'tone' => $booking->status->tone(),
            ])->all();

        return $this->group('bookings', 'Package bookings', 'ticket', (clone $query)->count(), 'travel.bookings.index', $term, $items);
    }

    private function clients(string $term): array
    {
        $seesAll = TravelAccess::managesAll($this->user) || TravelAccess::handlesPayments($this->user);
        $query = TravelClient::query()->search($term)
            ->when(! $seesAll, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('created_by', $this->user->id)
                ->orWhereHas('bookings', fn (Builder $bookings) => $bookings->visibleTo($this->user))));

        $items = (clone $query)->withCount('bookings')->orderBy('name')->limit(self::PerGroup)->get()
            ->map(fn (TravelClient $client) => [
                'title' => $client->name,
                'meta' => collect([$client->email, $client->maskedPhone(), $client->bookings_count.' '.str('booking')->plural($client->bookings_count)])->filter()->implode(' · '),
                'url' => $this->route('travel.clients.index', null, $client->name),
                'badge' => null,
                'tone' => 'neutral',
            ])->all();

        return $this->group('clients', 'Clients', 'users', (clone $query)->count(), 'travel.clients.index', $term, $items);
    }

    private function payments(string $term): array
    {
        $like = '%'.$term.'%';
        $query = TravelPayment::query()
            ->where(fn (Builder $query) => $query
                ->where('mpesa_receipt', 'like', $like)
                ->orWhere('reference', 'like', $like)
                ->orWhere('account_reference', 'like', $like)
                ->orWhere('payer_name', 'like', $like))
            ->when(! PaymentAccess::seesAll($this->user), fn (Builder $query) => $query->whereHas('booking', fn (Builder $booking) => $booking->visibleTo($this->user)));

        $items = (clone $query)->with('booking:id,reference')->latest('id')->limit(self::PerGroup)->get()
            ->map(fn (TravelPayment $payment) => [
                'title' => ($payment->mpesa_receipt ?? $payment->reference ?? 'Payment #'.$payment->id).' · KES '.number_format((float) $payment->amount),
                'meta' => collect([$payment->method->label(), $payment->channel->label(), $payment->booking?->reference ?? 'Not matched to a booking', $payment->paid_at?->format('j M Y')])->filter()->implode(' · '),
                'url' => $payment->booking ? $this->route('travel.bookings.show', $payment->booking) : $this->route('travel.payments.index', null, $term),
                'badge' => $payment->status->label(),
                'tone' => $payment->status->tone(),
            ])->all();

        return $this->group('payments', 'Payments', 'wallet', (clone $query)->count(), 'travel.payments.index', $term, $items);
    }

    private function flights(string $term): array
    {
        $query = FlightBooking::query()->search($term);
        $seesContacts = TravelAccess::managesAll($this->user);

        $items = (clone $query)->with('salesperson:id,name')->latest('booked_at')->limit(self::PerGroup)->get()
            ->map(fn (FlightBooking $booking) => [
                'title' => ($booking->booking_reference ?? $booking->external_id).' · '.$booking->customer_name,
                'meta' => collect([
                    $booking->route(),
                    $booking->airline_name,
                    $booking->departure_at?->format('j M Y, H:i'),
                    $seesContacts || $booking->salesperson_id === $this->user->id ? $booking->customer_email : null,
                    $booking->salesperson?->name,
                ])->filter()->implode(' · '),
                'url' => $this->route('travel.flights.show', $booking),
                'badge' => $booking->booking_status ? ucfirst(str_replace('_', ' ', $booking->booking_status)) : null,
                'tone' => $booking->isCancelled() ? 'danger' : 'neutral',
            ])->all();

        return $this->group('flights', 'Flight bookings', 'plane', (clone $query)->count(), 'travel.flights.index', $term, $items);
    }

    private function drivers(string $term): array
    {
        $like = '%'.$term.'%';
        $query = Driver::query()->where(fn (Builder $query) => $query
            ->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('vehicle_registration', 'like', $like));

        $items = (clone $query)->orderBy('name')->limit(self::PerGroup)->get()
            ->map(fn (Driver $driver) => [
                'title' => $driver->name,
                'meta' => collect([$driver->vehicle, $driver->vehicle_registration, $driver->phone])->filter()->implode(' · '),
                'url' => $this->route('travel.resources.index', ['tab' => 'drivers'], $driver->name),
                'badge' => $driver->status->label(),
                'tone' => $driver->status->tone(),
            ])->all();

        return $this->group('drivers', 'Drivers', 'truck', (clone $query)->count(), 'travel.resources.index', $term, $items, ['tab' => 'drivers']);
    }

    private function guides(string $term): array
    {
        $like = '%'.$term.'%';
        $query = Guide::query()->where(fn (Builder $query) => $query
            ->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('specialization', 'like', $like));

        $items = (clone $query)->orderBy('name')->limit(self::PerGroup)->get()
            ->map(fn (Guide $guide) => [
                'title' => $guide->name,
                'meta' => collect([$guide->specialization, implode(', ', (array) $guide->languages), $guide->phone])->filter()->implode(' · '),
                'url' => $this->route('travel.resources.index', ['tab' => 'guides'], $guide->name),
                'badge' => $guide->status->label(),
                'tone' => $guide->status->tone(),
            ])->all();

        return $this->group('guides', 'Guides', 'users', (clone $query)->count(), 'travel.resources.index', $term, $items, ['tab' => 'guides']);
    }

    private function influencers(string $term): array
    {
        $like = '%'.$term.'%';
        $query = InfluencerCode::query()
            ->whereHas('influencer', fn (Builder $influencer) => InfluencerAccess::scopeInfluencers($influencer, $this->user))
            ->where(fn (Builder $query) => $query
                ->where('code', 'like', '%'.strtoupper($term).'%')
                ->orWhereHas('influencer', fn (Builder $influencer) => $influencer->where('name', 'like', $like)
                    ->orWhereHas('platforms', fn (Builder $platforms) => $platforms->where('handle', 'like', $like))));

        $items = (clone $query)->with(['influencer:id,name', 'influencer.platforms'])->latest('id')->limit(self::PerGroup)->get()
            ->map(fn (InfluencerCode $code) => [
                'title' => $code->code.' · '.$code->influencer?->name,
                'meta' => collect([$code->influencer?->platformSummary(''), $code->termsLabel()])->filter()->implode(' · '),
                'url' => $code->influencer ? $this->route('travel.influencers.show', $code->influencer) : null,
                'badge' => $code->status->label(),
                'tone' => $code->status->tone(),
            ])->all();

        return $this->group('influencers', 'Influencer codes', 'megaphone', (clone $query)->count(), 'travel.influencers.index', $term, $items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, string>  $parameters
     * @return array{key: string, label: string, icon: string, total: int, more: ?string, items: list<array<string, mixed>>}
     */
    private function group(string $key, string $label, string $icon, int $total, string $listRoute, string $term, array $items, array $parameters = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'total' => $total,
            'more' => $total > count($items) ? $this->route($listRoute, $parameters, $term) : null,
            'items' => $items,
        ];
    }

    /**
     * A link if the route exists; $q is passed as the list page's search.
     *
     * @param  mixed  $parameters  a model, or query parameters
     */
    private function route(string $name, mixed $parameters = null, ?string $q = null): ?string
    {
        if (! Route::has($name)) {
            return null;
        }

        if (is_array($parameters) || $parameters === null) {
            return route($name, array_filter([...(array) $parameters, 'q' => $q]));
        }

        return route($name, $parameters);
    }
}
