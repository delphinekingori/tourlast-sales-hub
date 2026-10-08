<?php

namespace App\Livewire\Travel\Providers;

use App\Actions\Travel\Providers\SaveTravelProvider;
use App\Enums\Permission;
use App\Enums\Travel\IncidentStatus;
use App\Enums\Travel\RefundStatus;
use App\Enums\Travel\TravelBookingStatus;
use App\Livewire\Travel\Providers\Concerns\EditsContracts;
use App\Livewire\Travel\Providers\Concerns\RecordsIncidents;
use App\Models\AuditEvent;
use App\Models\MediaAsset;
use App\Models\PackageBooking;
use App\Models\PackageCancellation;
use App\Models\TravelProvider;
use App\Models\TravelRefund;
use App\Models\User;
use App\Support\Travel\ContractTerms;
use App\Support\Travel\TravelAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One provider: contacts, contracts, packages, incidents and performance.
 */
class Show extends Component
{
    use EditsContracts;
    use RecordsIncidents;
    use WithFileUploads;

    #[Locked]
    public int $providerId;

    public function mount(int|string $provider): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());
        $this->providerId = TravelProvider::query()->findOrFail($provider)->id;
    }

    public function archive(SaveTravelProvider $save): void
    {
        $save->setArchived($this->provider(), true, Auth::user());
        $this->dispatch('toast', message: 'Provider archived.');
    }

    public function restore(SaveTravelProvider $save): void
    {
        $save->setArchived($this->provider(), false, Auth::user());
        $this->dispatch('toast', message: 'Provider restored.');
    }

    public function render(): View
    {
        $user = Auth::user();
        $provider = TravelProvider::query()
            ->with([
                'owner:id,name,avatar_path',
                'creator:id,name',
                'propertyEngagement:id,name',
                'contracts' => fn ($query) => $query->latest('starts_on')->withCount('currentDocuments as documents_count')->with(['currentDocuments' => fn ($query) => $query->latest('id')]),
                'packages' => fn ($query) => $query->latest('updated_at')->with('owner:id,name'),
                'incidents' => fn ($query) => $query->latest('occurred_on')->with(['assignee:id,name', 'reporter:id,name']),
            ])
            ->findOrFail($this->providerId);

        $bookings = PackageBooking::query()->whereHas('package', fn (Builder $query) => $query->where('travel_provider_id', $provider->id));
        $sold = (clone $bookings)->whereIn('status', [TravelBookingStatus::Confirmed, TravelBookingStatus::Completed]);

        $lastActivity = collect([
            $provider->updated_at,
            AuditEvent::query()->where('subject_type', $provider->getMorphClass())->where('subject_id', $provider->id)->max('created_at'),
            $provider->contracts->max('updated_at'),
            $provider->packages->max('updated_at'),
            $provider->incidents->max('updated_at'),
        ])->filter()->map(fn ($date) => CarbonImmutable::parse($date))->max();

        return view('livewire.travel.providers.show', [
            'provider' => $provider,
            'canEdit' => TravelAccess::canChange($user, $provider->owner_id),
            'canManageAll' => TravelAccess::managesAll($user),
            'seesCommission' => ContractTerms::seesCommission($user, $provider),
            'seesDocuments' => ContractTerms::seesDocuments($user, $provider),
            'activeContract' => $provider->activeContract(),
            'performance' => [
                'packages' => $provider->packages->count(),
                'bookings' => (clone $sold)->count(),
                'revenue' => (float) (clone $sold)->sum('amount_total'),
                'slots' => (int) (clone $sold)->sum('travelers'),
                'cancellations' => PackageCancellation::query()->whereHas('booking.package', fn (Builder $query) => $query->where('travel_provider_id', $provider->id))->count(),
                'refunds' => (float) TravelRefund::query()->where('status', RefundStatus::Completed)
                    ->whereHas('booking.package', fn (Builder $query) => $query->where('travel_provider_id', $provider->id))->sum('amount'),
                'media' => MediaAsset::query()->where('travel_provider_id', $provider->id)->whereNull('archived_at')->count(),
                'openIncidents' => $provider->incidents->whereIn('status', [IncidentStatus::Open, IncidentStatus::Investigating])->count(),
                'lastActivity' => $lastActivity,
            ],
            'packageRoute' => Route::has('travel.packages.show'),
            'contractSeesCommission' => $this->contractProviderId ? ContractTerms::seesCommission($user, TravelProvider::query()->findOrFail($this->contractProviderId)) : false,
            'incidentProviders' => collect([$provider]),
            'incidentPackages' => $provider->packages,
            'travelUsers' => User::query()->active()->permission(Permission::AccessTravelSales->value)->orderBy('name')->get(['id', 'name']),
        ])->title($provider->name);
    }

    private function provider(): TravelProvider
    {
        return TravelProvider::query()->findOrFail($this->providerId);
    }
}
