<?php

namespace App\Livewire\Travel\Bookings;

use App\Actions\Travel\Bookings\CreatePackageBooking;
use App\Enums\Permission;
use App\Enums\Travel\DepartureStatus;
use App\Enums\Travel\InfluencerCodeScope;
use App\Enums\Travel\InfluencerCodeStatus;
use App\Enums\Travel\PackageStatus;
use App\Livewire\Travel\Bookings\Concerns\CapturesActionErrors;
use App\Livewire\Travel\Bookings\Concerns\EditsGuestRows;
use App\Models\InfluencerCode;
use App\Models\Package;
use App\Models\PackageDeparture;
use App\Models\TravelClient;
use App\Models\User;
use App\Support\Travel\InfluencerAccess;
use App\Support\Travel\InfluencerCodes;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    public string $codeSearch = '';

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $bookerTravelling = false;

    /** Empty until the salesperson answers; alone = only the client, group = the client and others, others = the client is not travelling. */
    public string $travellingFor = '';

    /** Type of the guest the "Add another guest" button adds. */
    public string $newGuestType = 'adult';

    public function mount(): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());

        $this->form = [
            'travel_client_id' => '',
            'client_name' => '',
            'client_email' => '',
            'client_phone' => '',
            'client_country' => 'Kenya',
            'adults' => '1',
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

            if ($this->bookerTravelling) {
                $this->travellingFor = $this->guestCount() > 1 ? 'group' : 'alone';
            }

            $this->applyBooker(overwrite: false);
        }

        if (in_array($key, ['client_name', 'client_phone', 'client_email', 'client_country'], true)) {
            $this->applyBooker();
        }
    }

    public function updatedBookerTravelling(): void
    {
        $this->travellingFor = ! $this->bookerTravelling ? 'others' : ($this->guestCount() > 1 ? 'group' : 'alone');

        if (! $this->bookerTravelling && isset($this->form['guests'][0])) {
            $this->form['guests'][0] = self::blankGuest($this->form['guests'][0]['type']);
        }

        $this->applyBooker();
    }

    /**
     * "Yes, alone" shrinks the party to one adult, "Yes, with others" makes sure
     * there is room for at least one more, "No" frees guest 1.
     */
    public function updatedTravellingFor(): void
    {
        if (! in_array($this->travellingFor, ['alone', 'group', 'others'], true)) {
            $this->travellingFor = 'alone';
        }

        $wasTravelling = $this->bookerTravelling;
        $this->bookerTravelling = $this->travellingFor !== 'others';

        if ($this->travellingFor === 'alone') {
            $this->form['adults'] = '1';
            $this->form['children'] = '0';
            $this->form['infants'] = '0';
            $this->syncGuestRows();
        } elseif ($this->travellingFor === 'group' && $this->guestCount() < 2) {
            $this->form['adults'] = (string) ((int) $this->form['adults'] + 1);
            $this->syncGuestRows();
        }

        if ($wasTravelling !== $this->bookerTravelling) {
            $this->updatedBookerTravelling();

            return;
        }

        $this->applyBooker(overwrite: false);
    }

    public function addGuest(): void
    {
        $type = in_array($this->newGuestType, ['adult', 'child', 'infant'], true) ? $this->newGuestType : 'adult';
        $field = ['adult' => 'adults', 'child' => 'children', 'infant' => 'infants'][$type];

        if ($this->guestCount() >= $this->maxGuestRows) {
            return;
        }

        $this->form[$field] = (string) ((int) $this->form[$field] + 1);
        $this->syncGuestRows();

        if ($this->travellingFor === 'alone') {
            $this->travellingFor = 'group';
        }

        $this->applyBooker(overwrite: false);
    }

    /**
     * Drops one guest row (never the first) and lowers its traveler count.
     */
    public function removeGuest(int $index): void
    {
        if ($index < 1 || ! isset($this->form['guests'][$index])) {
            return;
        }

        $rows = $this->form['guests'];
        unset($rows[$index]);
        $rows = array_values($rows);

        $count = fn (string $type): int => count(array_filter($rows, fn (array $row): bool => ($row['type'] ?? null) === $type));
        $this->form['adults'] = (string) $count('adult');
        $this->form['children'] = (string) $count('child');
        $this->form['infants'] = (string) $count('infant');
        $this->form['guests'] = $rows;

        $this->syncGuestRows();

        if ($this->bookerTravelling && count($this->form['guests']) === 1) {
            $this->travellingFor = 'alone';
        }

        $this->applyBooker(overwrite: false);
    }

    private function guestCount(): int
    {
        return max(0, (int) $this->form['adults']) + max(0, (int) $this->form['children']) + max(0, (int) $this->form['infants']);
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

    public function pickCode(int $id): void
    {
        $code = InfluencerCode::query()->find($id);
        $running = $code ? InfluencerCodes::resolve($code->code, 'packages', today()) : null;

        if (! $running) {
            return;
        }

        $this->form['influencer_code'] = $running->code;
        $this->codeSearch = '';
    }

    public function clearCode(): void
    {
        $this->form['influencer_code'] = '';
        $this->codeSearch = '';
    }

    /**
     * Running package codes whose code or influencer name contains the search,
     * limited to the user's own influencers. Any running code can still be
     * found by typing it in full.
     *
     * @return Collection<int, InfluencerCode>
     */
    private function codeMatches(): Collection
    {
        $term = trim($this->codeSearch);

        if ($term === '') {
            return collect();
        }

        $like = '%'.addcslashes($term, '\\%_').'%';

        return InfluencerCode::query()
            ->with('influencer:id,name')
            ->where('status', InfluencerCodeStatus::Active)
            ->whereIn('applies_to', [InfluencerCodeScope::Packages, InfluencerCodeScope::All])
            ->whereDate('starts_on', '<=', today())
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))
            ->where(fn (Builder $query) => $query
                ->whereHas('influencer', fn (Builder $influencer) => InfluencerAccess::scopeInfluencers($influencer, Auth::user())
                    ->where(fn (Builder $match) => $match->where('name', 'like', $like)->orWhereHas('codes', fn (Builder $code) => $code->where('code', 'like', $like))))
                ->orWhere('code', strtoupper($term)))
            ->orderBy('code')
            ->limit(8)
            ->get();
    }

    /**
     * Which of these codes belong to influencers the user may see, so the
     * influencer's name and the commission terms are shown for those only.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function visibleCodeIds(array $ids): array
    {
        return InfluencerCode::query()
            ->whereIn('id', $ids)
            ->whereHas('influencer', fn (Builder $influencer) => InfluencerAccess::scopeInfluencers($influencer, Auth::user()))
            ->pluck('id')
            ->all();
    }

    public function save(CreatePackageBooking $create): void
    {
        $this->resetErrorBag();

        if ($this->travellingFor === '') {
            $this->addError('travellingFor', 'Say whether the client is also travelling.');

            return;
        }

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
        $selectedCode = InfluencerCodes::resolve($this->form['influencer_code'], 'packages', today())?->load('influencer:id,name');
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
            'codeMatches' => $codeMatches = $this->codeMatches(),
            'codeDetailIds' => $this->visibleCodeIds($codeMatches->pluck('id')->push($selectedCode?->id)->filter()->all()),
            'selectedCode' => $selectedCode,
            'managesAll' => TravelAccess::managesAll($user),
            'salespeople' => TravelAccess::managesAll($user)
                ? User::query()->active()->permission(Permission::AccessTravelSales->value)->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }
}
