<?php

namespace App\Livewire\Travel\Contracts;

use App\Enums\Travel\CommissionModel;
use App\Enums\Travel\ContractStatus;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every provider contract, with expiry countdowns.
 */
#[Title('Contracts')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $provider = '';

    #[Url]
    public string $model = '';

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
        $this->reset(['search', 'status', 'provider', 'model']);
        $this->resetPage();
    }

    public function render(): View
    {
        $user = Auth::user();
        $search = trim($this->search);

        $contracts = ProviderContract::query()
            ->with(['provider:id,name,owner_id', 'provider.owner:id,name'])
            ->withCount('currentDocuments as documents_count')
            ->with(['currentDocuments' => fn ($query) => $query->latest('id')])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('contract_number', 'like', '%'.$search.'%')
                ->orWhere('contract_type', 'like', '%'.$search.'%')
                ->orWhereHas('provider', fn (Builder $provider) => $provider->where('name', 'like', '%'.$search.'%'))))
            ->when(ContractStatus::tryFrom($this->status), fn (Builder $query, ContractStatus $status) => $query->withEffectiveStatus($status))
            ->when($this->provider !== '', fn (Builder $query) => $query->where('travel_provider_id', (int) $this->provider))
            ->when(CommissionModel::tryFrom($this->model), fn (Builder $query, CommissionModel $model) => $query->where('commission_model', $model))
            ->orderByRaw('case when ends_on is null then 1 else 0 end')
            ->orderBy('ends_on')
            ->paginate(25);

        $count = fn (ContractStatus $status) => ProviderContract::query()->withEffectiveStatus($status)->count();

        return view('livewire.travel.contracts.index', [
            'contracts' => $contracts,
            'summary' => [
                'active' => $count(ContractStatus::Active) + $count(ContractStatus::ExpiringSoon),
                'expiring' => $count(ContractStatus::ExpiringSoon),
                'expired' => $count(ContractStatus::Expired),
                'pending' => ProviderContract::query()->whereIn('status', [ContractStatus::Draft, ContractStatus::PendingReview, ContractStatus::PendingApproval])->count(),
            ],
            'providers' => TravelProvider::query()->current()->orderBy('name')->get(['id', 'name']),
            'canSeeAllCommission' => TravelAccess::seesFinancials($user),
            'hasFilters' => $search !== '' || $this->status !== '' || $this->provider !== '' || $this->model !== '',
        ]);
    }
}
