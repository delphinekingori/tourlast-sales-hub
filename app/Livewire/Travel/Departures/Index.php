<?php

namespace App\Livewire\Travel\Departures;

use App\Actions\Travel\Departures\SaveDeparture;
use App\Enums\Travel\DepartureStatus;
use App\Enums\Travel\PackageStatus;
use App\Enums\Travel\ResourceStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Enums\Travel\TripStatus;
use App\Livewire\Travel\Bookings\Concerns\CapturesActionErrors;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\PackageDeparture;
use App\Models\TravelProvider;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Package inventory: every dated departure with its slots sold, reserved and
 * available. Departures are added and edited here by the package owner or a
 * Travel manager.
 */
#[Title('Inventory')]
class Index extends Component
{
    use CapturesActionErrors;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $package = '';

    #[Url]
    public string $provider = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public bool $past = false;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $showBookings = false;

    #[Locked]
    public ?int $bookingsFor = null;

    public function mount(): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());
        $this->resetForm();
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'package', 'provider', 'status', 'from', 'to', 'past'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'package', 'provider', 'status', 'from', 'to', 'past']);
        $this->resetPage();
    }

    public function create(?int $packageId = null): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());
        $this->resetForm();
        $this->resetErrorBag();

        if ($packageId && $package = Package::query()->find($packageId)) {
            $this->form['package_id'] = (string) $package->id;
            $this->form['capacity'] = (string) ($package->liveVersion?->default_capacity ?? 12);
            $this->form['ends_on'] = '';
        }

        $this->editingId = null;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $departure = PackageDeparture::query()->with('package')->findOrFail($id);
        TravelAccess::abortUnlessCanChange(Auth::user(), $departure->package->owner_id);

        $this->resetErrorBag();
        $this->editingId = $departure->id;
        $this->form = [
            'package_id' => (string) $departure->package_id,
            'starts_on' => $departure->starts_on->toDateString(),
            'start_time' => $departure->start_time ? substr((string) $departure->start_time, 0, 5) : '',
            'ends_on' => $departure->ends_on->toDateString(),
            'end_time' => $departure->end_time ? substr((string) $departure->end_time, 0, 5) : '',
            'capacity' => (string) $departure->capacity,
            'waitlist_count' => (string) $departure->waitlist_count,
            'status' => $departure->status->value,
            'trip_status' => $departure->trip_status->value,
            'driver_id' => (string) ($departure->driver_id ?? ''),
            'guide_id' => (string) ($departure->guide_id ?? ''),
            'allow_overbooking' => $departure->allow_overbooking,
            'override_conflict' => false,
            'notes' => (string) $departure->notes,
        ];
        $this->showForm = true;
    }

    public function save(SaveDeparture $save): void
    {
        $input = $this->form;

        foreach (['start_time', 'end_time', 'driver_id', 'guide_id', 'notes', 'waitlist_count'] as $key) {
            $input[$key] = ($input[$key] ?? '') === '' ? null : $input[$key];
        }

        $input = $this->prefix($input);
        $departure = $this->editingId ? PackageDeparture::query()->findOrFail($this->editingId) : null;

        $saved = $this->attempt(fn () => $save->handle(Auth::user(), $input, $departure));

        if (! $saved) {
            return;
        }

        $this->showForm = false;
        $this->dispatch('toast', message: $departure ? 'Departure updated.' : 'Departure added.');
    }

    public function viewBookings(int $id): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());
        $this->bookingsFor = PackageDeparture::query()->findOrFail($id)->id;
        $this->showBookings = true;
    }

    public function render(): View
    {
        $user = Auth::user();
        $base = $this->query();
        $statusIds = $this->statusIds($base);

        $departures = (clone $base)
            ->when($statusIds !== null, fn (Builder $query) => $query->whereIn('package_departures.id', $statusIds))
            ->withSlotCounts()
            ->withCount(['bookings as live_bookings_count' => fn (Builder $bookings) => $bookings->whereIn('status', [TravelBookingStatus::Pending, TravelBookingStatus::Confirmed, TravelBookingStatus::Completed])])
            ->with(['package:id,name,reference,status,owner_id,travel_provider_id,working_version_id,live_version_id', 'package.provider:id,name', 'package.owner:id,name', 'package.workingVersion:id,status', 'driver:id,name', 'guide:id,name'])
            ->orderBy('starts_on')
            ->paginate(25);

        $upcoming = PackageDeparture::query()->whereDate('starts_on', '>=', today())->whereNotIn('status', [DepartureStatus::Closed, DepartureStatus::Cancelled])->withSlotCounts()->get(['id', 'capacity', 'status']);

        $manageable = Package::query()->current()
            ->whereIn('status', [PackageStatus::Approved, PackageStatus::Published])
            ->whereNotNull('live_version_id')
            ->when(! TravelAccess::managesAll($user), fn (Builder $query) => $query->where('owner_id', $user->id))
            ->orderBy('name')
            ->get(['id', 'name', 'reference']);

        return view('livewire.travel.departures.index', [
            'departures' => $departures,
            'summary' => [
                'upcoming' => $upcoming->count(),
                'capacity' => $upcoming->sum('capacity'),
                'sold' => $upcoming->sum(fn (PackageDeparture $departure) => $departure->soldSlots()),
                'reserved' => $upcoming->sum(fn (PackageDeparture $departure) => $departure->reservedSlots()),
                'nearlyFull' => $upcoming->filter(fn (PackageDeparture $departure) => $departure->availabilityStatus() === DepartureStatus::NearlyFull)->count(),
                'full' => $upcoming->filter(fn (PackageDeparture $departure) => $departure->availabilityStatus() === DepartureStatus::Full)->count(),
            ],
            'packages' => Package::query()->current()->whereNotNull('live_version_id')->orderBy('name')->get(['id', 'name']),
            'providers' => TravelProvider::query()->current()->orderBy('name')->get(['id', 'name']),
            'manageablePackages' => $manageable,
            'drivers' => Driver::query()->where('status', ResourceStatus::Active)->orderBy('name')->get(['id', 'name']),
            'guides' => Guide::query()->where('status', ResourceStatus::Active)->orderBy('name')->get(['id', 'name']),
            'managesAll' => TravelAccess::managesAll($user),
            'userId' => $user->id,
            'bookingList' => $this->bookingList(),
        ]);
    }

    /**
     * @return Builder<PackageDeparture>
     */
    private function query(): Builder
    {
        return PackageDeparture::query()
            ->when($this->package !== '', fn (Builder $query) => $query->where('package_id', (int) $this->package))
            ->when($this->provider !== '', fn (Builder $query) => $query->whereHas('package', fn (Builder $package) => $package->where('travel_provider_id', (int) $this->provider)))
            ->when($this->search !== '', fn (Builder $query) => $query->whereHas('package', fn (Builder $package) => $package->search($this->search)))
            ->when($this->from !== '', fn (Builder $query) => $query->whereDate('starts_on', '>=', $this->from))
            ->when($this->to !== '', fn (Builder $query) => $query->whereDate('starts_on', '<=', $this->to))
            ->when(! $this->past && $this->from === '', fn (Builder $query) => $query->whereDate('ends_on', '>=', today()));
    }

    /**
     * Ids matching a worked-out status filter (open, nearly full, full, low
     * availability), or null when the filter doesn't need slot counts.
     *
     * @param  Builder<PackageDeparture>  $base
     * @return list<int>|null
     */
    private function statusIds(Builder $base): ?array
    {
        if ($this->status === '') {
            return null;
        }

        if (in_array($this->status, [DepartureStatus::Closed->value, DepartureStatus::Cancelled->value], true)) {
            return (clone $base)->where('status', $this->status)->pluck('id')->all();
        }

        return (clone $base)->withSlotCounts()->get(['id', 'capacity', 'status'])
            ->filter(fn (PackageDeparture $departure): bool => match ($this->status) {
                'low' => in_array($departure->availabilityStatus(), [DepartureStatus::NearlyFull, DepartureStatus::Full], true),
                default => $departure->availabilityStatus()->value === $this->status,
            })
            ->pluck('id')
            ->all();
    }

    /**
     * @return array{departure: PackageDeparture, bookings: Collection<int, PackageBooking>}|null
     */
    private function bookingList(): ?array
    {
        if (! $this->showBookings || ! $this->bookingsFor) {
            return null;
        }

        $departure = PackageDeparture::query()->withSlotCounts()->with('package:id,name')->find($this->bookingsFor);

        if (! $departure) {
            return null;
        }

        return [
            'departure' => $departure,
            'bookings' => PackageBooking::query()
                ->where('package_departure_id', $departure->id)
                ->whereIn('status', [TravelBookingStatus::Pending, TravelBookingStatus::Confirmed, TravelBookingStatus::Completed])
                ->with('client:id,name')
                ->orderBy('created_at')
                ->get(),
        ];
    }

    private function resetForm(): void
    {
        $this->form = [
            'package_id' => '',
            'starts_on' => '',
            'start_time' => '',
            'ends_on' => '',
            'end_time' => '',
            'capacity' => '12',
            'waitlist_count' => '0',
            'status' => DepartureStatus::Open->value,
            'trip_status' => TripStatus::Scheduled->value,
            'driver_id' => '',
            'guide_id' => '',
            'allow_overbooking' => false,
            'override_conflict' => false,
            'notes' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function prefix(array $input): array
    {
        $input['allow_overbooking'] = (bool) ($input['allow_overbooking'] ?? false);
        $input['override_conflict'] = (bool) ($input['override_conflict'] ?? false);

        return $input;
    }
}
