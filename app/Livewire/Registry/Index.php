<?php

namespace App\Livewire\Registry;

use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Enums\Permission;
use App\Models\Onboarding;
use App\Models\PropertyEngagement;
use App\Models\User;
use App\Support\EngagementRegistryFilters;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Property Engagement Registry')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $country = '';

    #[Url]
    public string $region = '';

    #[Url]
    public string $city = '';

    #[Url]
    public string $rep = '';

    #[Url]
    public string $stage = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $source = '';

    #[Url]
    public string $firstFrom = '';

    #[Url]
    public string $firstTo = '';

    #[Url]
    public string $lastFrom = '';

    #[Url]
    public string $lastTo = '';

    #[Url]
    public string $activity = '';

    #[Url]
    public string $onboarded = '';

    #[Url]
    public string $attention = '';

    #[Url]
    public bool $archived = false;

    #[Url]
    public string $sort = 'last';

    #[Url]
    public string $dir = 'desc';

    public function mount(): void
    {
        Gate::authorize('viewAny', PropertyEngagement::class);

        if ($this->archived && ! Gate::allows('create', PropertyEngagement::class)) {
            $this->archived = false;
        }
    }

    public function updating(string $property): void
    {
        if ($property === 'archived') {
            Gate::authorize('create', PropertyEngagement::class);
        }

        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, EngagementRegistryFilters::Sorts, true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->dir = in_array($column, ['first', 'last'], true) ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'country', 'region', 'city', 'rep', 'stage', 'status', 'source', 'firstFrom', 'firstTo', 'lastFrom', 'lastTo', 'activity', 'onboarded', 'attention', 'archived']);
        $this->resetPage();
    }

    public function render(): View
    {
        $filters = $this->filters();
        $user = Auth::user();

        return view('livewire.registry.index', [
            'filters' => $filters,
            'engagements' => $filters->query()->paginate(25),
            'summary' => $this->summary(),
            'attentionCounts' => $this->attentionCounts(),
            'unassignedSignups' => $user->can(Permission::ManageUsers->value) ? Onboarding::query()->unattributed()->count() : null,
            'salespeople' => User::query()->whereIn('id', PropertyEngagement::query()->withTrashed()->select('sales_rep_id'))->orderBy('name')->get(['id', 'name']),
            'countries' => PropertyEngagement::query()->distinct()->orderBy('country')->pluck('country'),
            'regions' => PropertyEngagement::query()->when($this->country, fn ($query) => $query->where('country', $this->country))->distinct()->orderBy('region')->pluck('region'),
            'cities' => PropertyEngagement::query()->when($this->region, fn ($query) => $query->where('region', $this->region))->distinct()->orderBy('city')->pluck('city'),
            'canManage' => $user->can('create', PropertyEngagement::class),
            'canExport' => $user->can('export', PropertyEngagement::class),
        ]);
    }

    /**
     * Registry-wide counts (not affected by filters).
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $byStatus = PropertyEngagement::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'engaged' => (int) $byStatus->only(EngagementRegistryFilters::openStatuses())->sum(),
            'onboarding' => PropertyEngagement::query()->whereIn('stage', EngagementStage::onboarding())->whereIn('status', EngagementRegistryFilters::openStatuses())->count(),
            'stalled' => (int) ($byStatus[EngagementStatus::Stalled->value] ?? 0),
            'lost' => (int) (($byStatus[EngagementStatus::Lost->value] ?? 0) + ($byStatus[EngagementStatus::Rejected->value] ?? 0)),
            'live' => PropertyEngagement::query()->where('stage', EngagementStage::Live)->count(),
        ];
    }

    /**
     * How many open properties are in each attention group (not affected by filters).
     *
     * @return array<string, int>
     */
    private function attentionCounts(): array
    {
        return collect(array_keys(EngagementRegistryFilters::Attention))
            ->mapWithKeys(fn (string $group): array => [$group => EngagementRegistryFilters::needingAttention(PropertyEngagement::query(), $group)->count()])
            ->all();
    }

    private function filters(): EngagementRegistryFilters
    {
        return EngagementRegistryFilters::fromArray([
            'q' => $this->search,
            'type' => $this->type,
            'country' => $this->country,
            'region' => $this->region,
            'city' => $this->city,
            'rep' => $this->rep,
            'stage' => $this->stage,
            'status' => $this->status,
            'source' => $this->source,
            'first_from' => $this->firstFrom,
            'first_to' => $this->firstTo,
            'last_from' => $this->lastFrom,
            'last_to' => $this->lastTo,
            'activity' => $this->activity,
            'onboarded' => $this->onboarded,
            'attention' => $this->attention,
            'archived' => $this->archived && Auth::user()->can('create', PropertyEngagement::class),
            'sort' => $this->sort,
            'dir' => $this->dir,
        ]);
    }
}
