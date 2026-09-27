<?php

namespace App\Livewire\Partners;

use App\Enums\Permission;
use App\Models\User;
use App\Support\PartnerRegisterFilters;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Partner Register')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'onboarded';

    #[Url]
    public string $salesperson = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ViewPartnerRegister->value), 403);

        if ($this->from === '' && $this->to === '' && ! request()->query()) {
            $this->from = now()->startOfMonth()->toDateString();
            $this->to = now()->endOfMonth()->toDateString();
        }
    }

    public function updating(string $property): void
    {
        $this->resetPage();
    }

    public function setRange(string $range): void
    {
        [$this->from, $this->to] = match ($range) {
            'today' => [now()->toDateString(), now()->toDateString()],
            'week' => [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()],
            'month' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
            'last-month' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            default => ['', ''],
        };
        $this->resetPage();
    }

    public function render(): View
    {
        $filters = $this->filters();
        $query = $filters->query();

        return view('livewire.partners.index', [
            'filters' => $filters,
            'partners' => (clone $query)->paginate(25),
            'total' => (clone $query)->count(),
            'byType' => (clone $query)->reorder()->selectRaw('property_type, count(*) as total')->groupBy('property_type')->pluck('total', 'property_type')->sortDesc(),
            'salespeople' => User::query()->sellers()->orderBy('name')->get(['id', 'name']),
            'canExport' => Auth::user()->can(Permission::ExportPartnerRegister->value),
            'exportQuery' => $filters->toQueryString(),
        ]);
    }

    private function filters(): PartnerRegisterFilters
    {
        return PartnerRegisterFilters::fromArray([
            'status' => $this->status,
            'salesperson' => $this->salesperson,
            'type' => $this->type,
            'from' => $this->from,
            'to' => $this->to,
            'q' => $this->search,
        ]);
    }
}
