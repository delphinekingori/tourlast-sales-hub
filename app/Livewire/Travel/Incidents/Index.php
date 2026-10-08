<?php

namespace App\Livewire\Travel\Incidents;

use App\Enums\Permission;
use App\Enums\Travel\IncidentSeverity;
use App\Enums\Travel\IncidentStatus;
use App\Enums\Travel\IncidentType;
use App\Livewire\Travel\Providers\Concerns\RecordsIncidents;
use App\Models\Package;
use App\Models\ProviderIncident;
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
 * The provider quality and incident log.
 */
#[Title('Provider incidents')]
class Index extends Component
{
    use RecordsIncidents;
    use WithPagination;

    #[Url]
    public string $provider = '';

    #[Url]
    public string $severity = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    public function mount(): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());
    }

    public function updating(string $property): void
    {
        if (! str_starts_with($property, 'incidentForm')) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['provider', 'severity', 'status', 'type']);
        $this->resetPage();
    }

    public function render(): View
    {
        $incidents = ProviderIncident::query()
            ->with(['provider:id,name,owner_id', 'package:id,name', 'assignee:id,name', 'reporter:id,name'])
            ->when($this->provider !== '', fn (Builder $query) => $query->where('travel_provider_id', (int) $this->provider))
            ->when(IncidentSeverity::tryFrom($this->severity), fn (Builder $query, IncidentSeverity $severity) => $query->where('severity', $severity))
            ->when(IncidentStatus::tryFrom($this->status), fn (Builder $query, IncidentStatus $status) => $query->where('status', $status))
            ->when(IncidentType::tryFrom($this->type), fn (Builder $query, IncidentType $type) => $query->where('type', $type))
            ->latest('occurred_on')
            ->latest('id')
            ->paginate(25);

        $formProvider = (int) ($this->incidentForm['travel_provider_id'] ?? 0);

        return view('livewire.travel.incidents.index', [
            'incidents' => $incidents,
            'summary' => [
                'open' => ProviderIncident::query()->whereIn('status', [IncidentStatus::Open, IncidentStatus::Investigating])->count(),
                'serious' => ProviderIncident::query()->whereIn('status', [IncidentStatus::Open, IncidentStatus::Investigating])
                    ->whereIn('severity', [IncidentSeverity::High, IncidentSeverity::Critical])->count(),
                'month' => ProviderIncident::query()->whereDate('occurred_on', '>=', now()->startOfMonth()->toDateString())->count(),
                'resolved' => ProviderIncident::query()->whereIn('status', [IncidentStatus::Resolved, IncidentStatus::Closed])->count(),
            ],
            'providers' => TravelProvider::query()->orderBy('name')->get(['id', 'name']),
            'incidentProviders' => TravelProvider::query()->current()->orderBy('name')->get(['id', 'name']),
            'incidentPackages' => $formProvider ? Package::query()->where('travel_provider_id', $formProvider)->orderBy('name')->get(['id', 'name']) : collect(),
            'travelUsers' => User::query()->active()->permission(Permission::AccessTravelSales->value)->orderBy('name')->get(['id', 'name']),
            'hasFilters' => $this->provider !== '' || $this->severity !== '' || $this->status !== '' || $this->type !== '',
        ]);
    }
}
