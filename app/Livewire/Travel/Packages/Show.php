<?php

namespace App\Livewire\Travel\Packages;

use App\Actions\Travel\Packages\ReviewPackage;
use App\Enums\Travel\TravelBookingStatus;
use App\Livewire\Travel\Packages\Concerns\HandlesPackageActions;
use App\Models\AuditEvent;
use App\Models\Package;
use App\Models\PackageBooking;
use App\Models\PackageDeparture;
use App\Models\PackageVersion;
use App\Support\Travel\PackageContent;
use App\Support\Travel\PackageReadiness;
use App\Support\Travel\PublishGate;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One package: content, prices, availability, bookings, approval history and
 * activity, in tabs. Shows the version under review (if any) beside the live one.
 */
class Show extends Component
{
    use HandlesPackageActions;

    #[Locked]
    public int $packageId;

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $view = '';

    #[Url(as: 'bookings')]
    public string $bookingStatus = '';

    public const Tabs = [
        'overview' => 'Overview',
        'itinerary' => 'Itinerary',
        'pricing' => 'Pricing',
        'availability' => 'Availability',
        'provider' => 'Provider & contract',
        'team' => 'Driver & guide',
        'media' => 'Media',
        'bookings' => 'Bookings',
        'approvals' => 'Approval history',
        'activity' => 'Activity',
    ];

    public function mount(Package $package): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());
        $this->packageId = $package->id;

        if (! array_key_exists($this->tab, self::Tabs)) {
            $this->tab = 'overview';
        }
    }

    public function render(): View
    {
        $user = Auth::user();
        $package = Package::query()
            ->with([
                'provider', 'contract', 'owner:id,name', 'creator:id,name', 'updater:id,name', 'publisher:id,name',
                'driver', 'guide', 'liveVersion.itineraryDays', 'workingVersion.itineraryDays', 'workingVersion.submitter:id,name',
            ])
            ->findOrFail($this->packageId);

        $working = $package->workingVersion;
        $live = $package->liveVersion;
        $shown = $this->view === 'live' && $live ? $live : ($working ?? $live);

        return view('livewire.travel.packages.show', [
            'package' => $package,
            'version' => $shown,
            'working' => $working,
            'live' => $live,
            'canChange' => TravelAccess::canChange($user, $package->owner_id) && ! $package->archived_at,
            'canReview' => $working ? ReviewPackage::canReview($user, $package, $working) : false,
            'showFinancials' => PackageContent::mayEditFinancials($user, $package),
            'readiness' => $working ? PackageReadiness::check($package, $working) : [],
            'publishGate' => $live ? PublishGate::missing($package) : [],
            'media' => $this->tab === 'media' || $this->tab === 'overview' ? $package->media()->get() : collect(),
            'departures' => $this->tab === 'availability'
                ? PackageDeparture::query()->where('package_id', $package->id)->withSlotCounts()->with(['driver:id,name', 'guide:id,name'])->orderBy('starts_on')->get()
                : collect(),
            'bookings' => $this->tab === 'bookings' ? $this->bookings($package) : collect(),
            'versions' => $this->tab === 'approvals'
                ? PackageVersion::query()->where('package_id', $package->id)->with(['approvals.user:id,name', 'creator:id,name', 'submitter:id,name'])->orderByDesc('major')->orderByDesc('minor')->get()
                : collect(),
            'activity' => $this->tab === 'activity' ? $this->activity($package) : collect(),
            'departuresUrl' => Route::has('travel.departures.index') ? route('travel.departures.index', ['package' => $package->id]) : null,
            'bookingUrl' => Route::has('travel.bookings.show'),
            'duplicateMatches' => $this->duplicateMatches(),
            'providerUrl' => Route::has('travel.providers.show') ? route('travel.providers.show', $package->travel_provider_id) : null,
        ]);
    }

    /**
     * @return Collection<int, PackageBooking>
     */
    private function bookings(Package $package): Collection
    {
        return PackageBooking::query()
            ->where('package_id', $package->id)
            ->visibleTo(Auth::user())
            ->when(TravelBookingStatus::tryFrom($this->bookingStatus), fn (Builder $query, TravelBookingStatus $status) => $query->where('status', $status))
            ->when($this->bookingStatus === 'refunded', fn (Builder $query) => $query->where('amount_refunded', '>', 0))
            ->with(['client:id,name', 'departure:id,starts_on', 'salesperson:id,name', 'driver:id,name', 'guide:id,name'])
            ->latest()
            ->limit(200)
            ->get();
    }

    /**
     * @return Collection<int, AuditEvent>
     */
    private function activity(Package $package): Collection
    {
        $versionIds = PackageVersion::query()->where('package_id', $package->id)->pluck('id');

        return AuditEvent::query()
            ->with('user:id,name')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('subject_type', $package->getMorphClass())->where('subject_id', $package->id))
                ->orWhere(fn (Builder $query) => $query->where('subject_type', (new PackageVersion)->getMorphClass())->whereIn('subject_id', $versionIds)))
            ->latest('created_at')
            ->latest('id')
            ->limit(200)
            ->get();
    }
}
