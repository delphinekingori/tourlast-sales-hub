<?php

namespace App\Livewire\Onboardings;

use App\Enums\OnboardingStatus;
use App\Models\Onboarding;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('My Onboardings')]
class Mine extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    #[Locked]
    public ?int $viewingId = null;

    public bool $showDetail = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->role()?->earnsReferrals(), 403);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['filter', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function view(int $onboardingId): void
    {
        $this->viewingId = Onboarding::withTrashed()->where('user_id', Auth::id())->findOrFail($onboardingId)->id;
        $this->showDetail = true;
    }

    /**
     * Archived properties (the source app deleted them) stay on this list with
     * a Deleted badge: the salesperson keeps the credit they earned.
     */
    public function render(): View
    {
        $base = Onboarding::withTrashed()->where('user_id', Auth::id());

        return view('livewire.onboardings.mine', [
            'onboardings' => (clone $base)
                ->when($this->filter === 'awaiting', fn ($query) => $query->awaitingApproval())
                ->when($this->filter === 'onboarded', fn ($query) => $query->onboarded())
                ->when($this->filter === 'inactive', fn ($query) => $query->where('status', OnboardingStatus::Inactive))
                ->when($this->filter === 'rejected', fn ($query) => $query->where('status', OnboardingStatus::Rejected))
                ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->where('property_name', 'like', "%{$this->search}%")
                    ->orWhere('location', 'like', "%{$this->search}%")
                    ->orWhere('contact_name', 'like', "%{$this->search}%")))
                ->latest('submitted_at')
                ->paginate(20),
            'counts' => [
                'all' => (clone $base)->count(),
                'awaiting' => (clone $base)->awaitingApproval()->count(),
                'onboarded' => (clone $base)->onboarded()->count(),
                'inactive' => (clone $base)->where('status', OnboardingStatus::Inactive)->count(),
                'rejected' => (clone $base)->where('status', OnboardingStatus::Rejected)->count(),
            ],
            'viewing' => $this->viewingId ? Onboarding::withTrashed()->with('statusChanges', 'attributionChanges.changedBy')->where('user_id', Auth::id())->find($this->viewingId) : null,
        ]);
    }
}
