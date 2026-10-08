<?php

namespace App\Livewire\Travel\Packages;

use App\Enums\Permission;
use App\Enums\Travel\PackageStatus;
use App\Livewire\Travel\Packages\Concerns\HandlesPackageActions;
use App\Models\Package;
use App\Models\TravelProvider;
use App\Models\User;
use App\Support\Travel\PackageListing;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every tour and experience package with its approval, publication,
 * contract and slot status.
 */
#[Title('Packages')]
class Index extends Component
{
    use HandlesPackageActions;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $approval = '';

    #[Url]
    public string $provider = '';

    #[Url]
    public string $destination = '';

    #[Url]
    public string $owner = '';

    #[Url]
    public string $contract = '';

    public function mount(): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status', 'approval', 'provider', 'destination', 'owner', 'contract'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'approval', 'provider', 'destination', 'owner', 'contract']);
        $this->resetPage();
    }

    public function render(): View
    {
        $user = Auth::user();
        $packages = PackageListing::query($user, [
            'search' => $this->search,
            'status' => $this->status,
            'approval' => $this->approval,
            'provider' => $this->provider,
            'destination' => $this->destination,
            'owner' => $this->owner,
            'contract' => $this->contract,
        ])->latest('updated_at')->paginate(25);

        return view('livewire.travel.packages.index', [
            'packages' => $packages,
            'summary' => PackageListing::summary(),
            'statuses' => PackageStatus::cases(),
            'providers' => TravelProvider::query()->current()->orderBy('name')->get(['id', 'name']),
            'destinations' => Package::query()->whereNull('archived_at')->distinct()->orderBy('destination')->pluck('destination'),
            'owners' => TravelAccess::managesAll($user)
                ? User::query()->permission(Permission::AccessTravelSales->value)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'managesAll' => TravelAccess::managesAll($user),
            'hasFilters' => $this->search !== '' || $this->status !== '' || $this->approval !== '' || $this->provider !== '' || $this->destination !== '' || $this->owner !== '' || $this->contract !== '',
            'duplicateMatches' => $this->duplicateMatches(),
        ]);
    }
}
