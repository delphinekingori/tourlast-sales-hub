<?php

namespace App\Livewire\Travel\Bookings;

use App\Actions\Travel\Bookings\CreatePackageBooking;
use App\Enums\Permission;
use App\Enums\Travel\DepartureStatus;
use App\Enums\Travel\PackageStatus;
use App\Livewire\Travel\Bookings\Concerns\CapturesActionErrors;
use App\Livewire\Travel\Bookings\Concerns\EditsGuestRows;
use App\Models\Package;
use App\Models\PackageDeparture;
use App\Models\TravelClient;
use App\Models\User;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Takes a new package booking: package → departure → client → travelers,
 * with one guest row per traveler. When the booker is travelling, guest 1
 * mirrors the client's details.
 */
#[Title('New booking')]
class Create extends Component
{
    use CapturesActionErrors;
    use EditsGuestRows;

    #[Url(as: 'package')]
    public string $packageId = '';

    #[Url(as: 'departure')]
    public string $departureId = '';

    public string $clientSearch = '';

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $bookerTravelling = true;

    public function mount(): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());

        $this->form = [
            'travel_client_id' => '',
            'client_name' => '',
            'client_email' => '',
            'client_phone' => '',
            'client_country' => 'Kenya',
            'adults' => '2',
            'children' => '0',
            'infants' => '0',
            'special_requirements' => '',
            'dietary_requirements' => '',
            'emergency_contact_name' => '',
            'emergency_contact_phone' => '',
            'notes' => '',
            'influencer_code' => '',
            'salesperson_id' => '',
            'amount_override' => '',
            'override_reason' => '',
            'guests' => [],
        ];

        $this->syncGuestRows();
        $this->applyBooker();
    }

    /**
     * Grows or shrinks the guest rows with the counts, and mirrors client
     * details onto guest 1 while the booker is travelling.
     */
    public function updatedForm(mixed $value, string $key): void
    {
        if (in_array($key, ['adults', 'children', 'infants'], true)) {
            $this->syncGuestRows();
            $this->applyBooker(overwrite: false);
        }

        if (in_array($key, ['client_name', 'client_phone', 'client_email', 'client_country'], true)) {
            $this->applyBooker();
        }
    }

    public function updatedBookerTravelling(): void
    {
        if (! $this->bookerTravelling && isset($this->form['guests'][0])) {
            $this->form['guests'][0] = self::blankGuest($this->form['guests'][0]['type']);
        }

        $this->applyBooker();
    }

    public function updatedPackageId(): void
    {
        $this->departureId = '';
    }

    public function pickClient(int $id): void
    {
        $client = TravelClient::query()->findOrFail($id);
        $this->form['travel_client_id'] = (string) $client->id;
        $this->clientSearch = '';
        $this->applyBooker();
    }

    public function clearClient(): void
    {
        $this->form['travel_client_id'] = '';
        $this->applyBooker();
    }

    public function save(CreatePackageBooking $create): void
    {
        $this->resetErrorBag();
        $this->applyBooker(overwrite: false);

        $input = array_merge($this->form, [
            'package_id' => $this->packageId,
            'package_departure_id' => $this->departureId,
        ]);

        foreach ($input as $key => $value) {
            if ($value === '') {
                $input[$key] = null;
            }
        }

        $booking = $this->attempt(fn () => $create->handle(Auth::user(), $input));

        if (! $booking) {
            return;
        }

        $this->dispatch('toast', message: 'Booking '.$booking->reference.' taken. Slots are held for '.config('travel.reservation_hold_hours').' hours until it is confirmed.');
        $this->redirectRoute('travel.bookings.show', ['booking' => $booking->id], navigate: true);
    }

    private function syncGuestRows(): void
    {
        $this->form['guests'] = $this->resizeGuestRows(
            $this->form['guests'] ?? [],
            max(0, (int) $this->form['adults']),
            max(0, (int) $this->form['children']),
            max(0, (int) $this->form['infants']),
        );
    }

    /**
     * Marks guest 1 as the booker and copies the client's name, phone and
     * email into it (only into empty fields unless $overwrite). The
     * client's country only ever fills an empty nationality.
     */
    private function applyBooker(bool $overwrite = true): void
    {
        foreach ($this->form['guests'] as $index => $guest) {
            $this->form['guests'][$index]['is_booker'] = $this->bookerTravelling && $index === 0;
        }

        if (! $this->bookerTravelling || ! isset($this->form['guests'][0])) {
            return;
        }

        $client = $this->form['travel_client_id'] !== '' ? TravelClient::query()->find((int) $this->form['travel_client_id']) : null;
        $details = $client
            ? ['full_name' => $client->name, 'phone' => $client->phone, 'email' => $client->email, 'nationality' => $client->country]
            : ['full_name' => $this->form['client_name'], 'phone' => $this->form['client_phone'], 'email' => $this->form['client_email'], 'nationality' => $this->form['client_country']];

        foreach ($details as $field => $value) {
            if (filled($value) && (($overwrite && $field !== 'nationality') || blank($this->form['guests'][0][$field]))) {
                $this->form['guests'][0][$field] = (string) $value;
            }
        }
    }

    public function render(): View
    {
        $user = Auth::user();
        $package = $this->packageId !== '' ? Package::query()->with('liveVersion')->find((int) $this->packageId) : null;

        $departures = $package
            ? PackageDeparture::query()
                ->where('package_id', $package->id)
                ->whereDate('starts_on', '>=', today())
                ->whereNotIn('status', [DepartureStatus::Closed, DepartureStatus::Cancelled])
                ->withSlotCounts()
                ->orderBy('starts_on')
                ->get()
            : collect();

        $version = $package?->liveVersion;
        $price = $version ? $version->priceFor((int) $this->form['adults'], (int) $this->form['children'], (int) $this->form['infants']) : null;

        return view('livewire.travel.bookings.create', [
            'packages' => Package::query()->current()->whereIn('status', [PackageStatus::Approved, PackageStatus::Published])->whereNotNull('live_version_id')->orderBy('name')->get(['id', 'name', 'reference']),
            'package' => $package,
            'version' => $version,
            'departures' => $departures,
            'price' => $price,
            'client' => $this->form['travel_client_id'] !== '' ? TravelClient::query()->find((int) $this->form['travel_client_id']) : null,
            'clientMatches' => strlen(trim($this->clientSearch)) >= 2
                ? TravelClient::query()->search($this->clientSearch)->orderBy('name')->limit(8)->get()
                : collect(),
            'managesAll' => TravelAccess::managesAll($user),
            'salespeople' => TravelAccess::managesAll($user)
                ? User::query()->active()->permission(Permission::AccessTravelSales->value)->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }
}
