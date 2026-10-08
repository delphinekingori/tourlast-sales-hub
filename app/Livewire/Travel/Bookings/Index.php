<?php

namespace App\Livewire\Travel\Bookings;

use App\Enums\Permission;
use App\Enums\Travel\BookingPaymentStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\User;
use App\Support\Travel\PreTripChecklist;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Package bookings: the salesperson's own (and those on their packages);
 * everyone's for Travel managers and Accounts.
 */
#[Title('Package bookings')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $payment = '';

    #[Url]
    public string $package = '';

    #[Url]
    public string $salesperson = '';

    #[Url]
    public string $bookedFrom = '';

    #[Url]
    public string $bookedTo = '';

    #[Url]
    public string $travelFrom = '';

    #[Url]
    public string $travelTo = '';

    #[Url]
    public bool $pretrip = false;

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless(TravelAccess::works($user) || TravelAccess::handlesPayments($user), 403);
    }

    public function updating(string $property): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'payment', 'package', 'salesperson', 'bookedFrom', 'bookedTo', 'travelFrom', 'travelTo', 'pretrip']);
        $this->resetPage();
    }

    public function render(): View
    {
        $user = Auth::user();
        $seesAll = TravelAccess::managesAll($user) || TravelAccess::handlesPayments($user);
        $base = $this->query($user, $seesAll);
        $pretripIds = PreTripChecklist::idsNeedingAction($this->visible($user));

        $bookings = (clone $base)
            ->when($this->pretrip, fn (Builder $query) => $query->whereIn('package_bookings.id', $pretripIds))
            ->with(['package:id,name,owner_id,driver_id,guide_id', 'package.driver:id,name', 'package.guide:id,name', 'departure:id,starts_on,ends_on,driver_id,guide_id', 'departure.driver:id,name', 'departure.guide:id,name', 'client:id,name', 'salesperson:id,name', 'driver:id,name', 'guide:id,name'])
            ->latest()
            ->paginate(25);

        $visible = $this->visible($user);

        return view('livewire.travel.bookings.index', [
            'bookings' => $bookings,
            'summary' => [
                'total' => (clone $visible)->count(),
                'pending' => (clone $visible)->where('status', TravelBookingStatus::Pending)->count(),
                'confirmed' => (clone $visible)->where('status', TravelBookingStatus::Confirmed)->count(),
                'travelers' => (int) (clone $visible)->whereIn('status', [TravelBookingStatus::Confirmed, TravelBookingStatus::Completed])->sum('travelers'),
                'value' => (float) (clone $visible)->whereIn('status', [TravelBookingStatus::Pending, TravelBookingStatus::Confirmed, TravelBookingStatus::Completed])->sum('amount_total'),
                'pretrip' => count($pretripIds),
            ],
            'pretripIds' => array_flip($pretripIds),
            'packages' => Package::query()->whereNotNull('live_version_id')->orderBy('name')->get(['id', 'name']),
            'salespeople' => $seesAll ? User::query()->permission(Permission::AccessTravelSales->value)->orderBy('name')->get(['id', 'name']) : collect(),
            'seesAll' => $seesAll,
            'canBook' => TravelAccess::works($user),
        ]);
    }

    /**
     * @return Builder<PackageBooking>
     */
    private function visible(User $user): Builder
    {
        return PackageBooking::query()->visibleTo($user);
    }

    /**
     * @return Builder<PackageBooking>
     */
    private function query(User $user, bool $seesAll): Builder
    {
        return $this->visible($user)
            ->search($this->search)
            ->when(TravelBookingStatus::tryFrom($this->status), fn (Builder $query, TravelBookingStatus $status) => $query->where('status', $status))
            ->when(BookingPaymentStatus::tryFrom($this->payment), fn (Builder $query, BookingPaymentStatus $status) => $query->where('payment_status', $status))
            ->when($this->package !== '', fn (Builder $query) => $query->where('package_id', (int) $this->package))
            ->when($seesAll && $this->salesperson !== '', fn (Builder $query) => $query->where('salesperson_id', (int) $this->salesperson))
            ->when($this->bookedFrom !== '', fn (Builder $query) => $query->whereDate('created_at', '>=', $this->bookedFrom))
            ->when($this->bookedTo !== '', fn (Builder $query) => $query->whereDate('created_at', '<=', $this->bookedTo))
            ->when($this->travelFrom !== '', fn (Builder $query) => $query->whereHas('departure', fn (Builder $departure) => $departure->whereDate('starts_on', '>=', $this->travelFrom)))
            ->when($this->travelTo !== '', fn (Builder $query) => $query->whereHas('departure', fn (Builder $departure) => $departure->whereDate('starts_on', '<=', $this->travelTo)));
    }
}
