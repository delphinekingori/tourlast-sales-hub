<?php

namespace App\Livewire\Travel\Resources;

use App\Actions\Travel\Resources\SaveTripResource;
use App\Enums\Travel\ResourceStatus;
use App\Livewire\Travel\Bookings\Concerns\CapturesActionErrors;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\TravelProvider;
use App\Support\Travel\ResourceSchedule;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Drivers and guides who can be assigned to packages, departures and
 * bookings, with the trips each is already on.
 */
#[Title('Drivers & guides')]
class Index extends Component
{
    use CapturesActionErrors;
    use WithPagination;

    #[Url]
    public string $tab = 'drivers';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());
        $this->tab = in_array($this->tab, ['drivers', 'guides'], true) ? $this->tab : 'drivers';
        $this->resetForm();
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['tab', 'search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());
        $this->resetErrorBag();
        $this->resetForm();
        $this->editingId = null;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());
        $resource = $this->model()::query()->findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $resource->id;
        $this->form = [
            'name' => $resource->name,
            'phone' => (string) $resource->phone,
            'travel_provider_id' => (string) ($resource->travel_provider_id ?? ''),
            'status' => $resource->status->value,
            'notes' => (string) $resource->notes,
            'vehicle' => (string) ($resource->vehicle ?? ''),
            'vehicle_registration' => (string) ($resource->vehicle_registration ?? ''),
            'license_number' => (string) ($resource->license_number ?? ''),
            'languages' => implode(', ', $resource->languages ?? []),
            'specialization' => (string) ($resource->specialization ?? ''),
        ];
        $this->showForm = true;
    }

    public function save(SaveTripResource $save): void
    {
        $kind = $this->tab === 'guides' ? 'guide' : 'driver';
        $input = $this->form;
        $input['travel_provider_id'] = $input['travel_provider_id'] !== '' ? (int) $input['travel_provider_id'] : null;
        $input['languages'] = collect(explode(',', (string) $input['languages']))->map(fn (string $language) => trim($language))->filter()->values()->all();

        foreach (['phone', 'notes', 'vehicle', 'vehicle_registration', 'license_number', 'specialization'] as $key) {
            $input[$key] = ($input[$key] ?? '') === '' ? null : $input[$key];
        }

        $existing = $this->editingId ? $this->model()::query()->findOrFail($this->editingId) : null;
        $saved = $this->attempt(fn () => $save->handle(Auth::user(), $kind, $input, $existing));

        if ($saved) {
            $this->showForm = false;
            $this->dispatch('toast', message: ucfirst($kind).' saved.');
        }
    }

    public function render(): View
    {
        $model = $this->model();

        $resources = $model::query()
            ->with('provider:id,name')
            ->when($this->search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('phone', 'like', '%'.$this->search.'%')
                ->when($this->tab === 'drivers', fn (Builder $query) => $query->orWhere('vehicle_registration', 'like', '%'.$this->search.'%'))))
            ->when(ResourceStatus::tryFrom($this->status), fn (Builder $query, ResourceStatus $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.travel.resources.index', [
            'resources' => $resources,
            'upcoming' => $resources->getCollection()->mapWithKeys(fn (Driver|Guide $resource) => [$resource->id => ResourceSchedule::upcomingFor($resource, 4)]),
            'providers' => TravelProvider::query()->current()->orderBy('name')->get(['id', 'name']),
            'counts' => ['drivers' => Driver::query()->count(), 'guides' => Guide::query()->count()],
        ]);
    }

    /**
     * @return class-string<Driver|Guide>
     */
    private function model(): string
    {
        return $this->tab === 'guides' ? Guide::class : Driver::class;
    }

    private function resetForm(): void
    {
        $this->form = [
            'name' => '', 'phone' => '', 'travel_provider_id' => '', 'status' => ResourceStatus::Active->value, 'notes' => '',
            'vehicle' => '', 'vehicle_registration' => '', 'license_number' => '', 'languages' => 'English, Swahili', 'specialization' => '',
        ];
    }
}
