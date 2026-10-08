<?php

namespace App\Livewire\Travel\Providers;

use App\Enums\Permission;
use App\Enums\Travel\ContractStatus;
use App\Enums\Travel\TravelProviderStatus;
use App\Enums\Travel\TravelProviderType;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Models\User;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every tour and experience provider. Travel salespeople see all of them
 * (to avoid duplicates) and edit their own; Travel managers edit all.
 */
#[Title('Providers')]
class Index extends Component
{
    use WithPagination;

    public const ContractStates = [
        'in_force' => 'Contract in force',
        'expiring' => 'Contract expiring (30 days)',
        'expired' => 'Contract expired',
        'none' => 'No active contract',
    ];

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $owner = '';

    #[Url]
    public string $contract = '';

    #[Url]
    public bool $mine = false;

    #[Url]
    public bool $archived = false;

    public function mount(): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());
    }

    public function updating(string $property): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'status', 'owner', 'contract', 'mine', 'archived']);
        $this->resetPage();
    }

    public function render(): View
    {
        $user = Auth::user();
        $soon = today()->addDays(ProviderContract::ExpiringSoonDays)->toDateString();

        $providers = $this->query($user)
            ->with(['owner:id,name,avatar_path', 'contracts' => fn ($query) => $query->where('status', ContractStatus::Active)])
            ->withCount('packages')
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.travel.providers.index', [
            'providers' => $providers,
            'summary' => [
                'active' => TravelProvider::query()->current()->whereIn('status', [TravelProviderStatus::Active, TravelProviderStatus::Contracted])->count(),
                'prospects' => TravelProvider::query()->current()->whereIn('status', [TravelProviderStatus::Prospect, TravelProviderStatus::Contacted, TravelProviderStatus::Interested, TravelProviderStatus::Negotiation])->count(),
                'contracted' => TravelProvider::query()->current()->withContractInForce()->count(),
                'expiring' => ProviderContract::query()->where('status', ContractStatus::Active)
                    ->whereDate('ends_on', '>=', today()->toDateString())->whereDate('ends_on', '<=', $soon)->count(),
            ],
            'owners' => TravelAccess::managesAll($user)
                ? User::query()->permission(Permission::AccessTravelSales->value)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'canManageAll' => TravelAccess::managesAll($user),
            'hasFilters' => $this->search !== '' || $this->type !== '' || $this->status !== '' || $this->owner !== '' || $this->contract !== '' || $this->mine || $this->archived,
        ]);
    }

    /**
     * @return Builder<TravelProvider>
     */
    private function query(User $user): Builder
    {
        $today = today()->toDateString();
        $soon = today()->addDays(ProviderContract::ExpiringSoonDays)->toDateString();
        $activeContract = fn (Builder $query) => $query->where('status', ContractStatus::Active)->whereDate('starts_on', '<=', $today);

        return TravelProvider::query()
            ->when($this->archived, fn (Builder $query) => $query->whereNotNull('archived_at'), fn (Builder $query) => $query->whereNull('archived_at'))
            ->search($this->search)
            ->when(TravelProviderType::tryFrom($this->type), fn (Builder $query, TravelProviderType $type) => $query->where('provider_type', $type))
            ->when(TravelProviderStatus::tryFrom($this->status), fn (Builder $query, TravelProviderStatus $status) => $query->where('status', $status))
            ->when($this->mine, fn (Builder $query) => $query->where('owner_id', $user->id))
            ->when($this->owner !== '' && TravelAccess::managesAll($user), fn (Builder $query) => $query->where('owner_id', (int) $this->owner))
            ->when($this->contract === 'in_force', fn (Builder $query) => $query->withContractInForce())
            ->when($this->contract === 'expiring', fn (Builder $query) => $query->whereHas('contracts', fn (Builder $contracts) => $activeContract($contracts)
                ->whereDate('ends_on', '>=', $today)->whereDate('ends_on', '<=', $soon)))
            ->when($this->contract === 'expired', fn (Builder $query) => $query
                ->whereHas('contracts', fn (Builder $contracts) => $contracts->where('status', ContractStatus::Active)->whereDate('ends_on', '<', $today))
                ->whereDoesntHave('contracts', fn (Builder $contracts) => $activeContract($contracts)
                    ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))))
            ->when($this->contract === 'none', fn (Builder $query) => $query->whereDoesntHave('contracts', fn (Builder $contracts) => $activeContract($contracts)
                ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))));
    }
}
