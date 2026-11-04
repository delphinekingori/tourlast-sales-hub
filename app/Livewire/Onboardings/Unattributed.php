<?php

namespace App\Livewire\Onboardings;

use App\Actions\AssignOnboarding;
use App\Enums\Permission;
use App\Models\Onboarding;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Unattributed signups')]
class Unattributed extends Component
{
    use WithPagination;

    public bool $showAssign = false;

    /** @var list<int> */
    #[Locked]
    public array $assigningIds = [];

    /** @var list<string> */
    public array $selected = [];

    public string $salespersonId = '';

    public string $reason = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ManageUsers->value), 403);
    }

    public function openAssign(int $onboardingId): void
    {
        $this->openFor([Onboarding::query()->unattributed()->findOrFail($onboardingId)->id]);
    }

    public function openAssignSelected(): void
    {
        $ids = Onboarding::query()->unattributed()->whereIn('id', array_map('intval', $this->selected))->pluck('id')->all();

        if ($ids === []) {
            $this->addError('selected', 'Tick at least one property to assign.');

            return;
        }

        $this->openFor($ids);
    }

    public function assign(AssignOnboarding $assignOnboarding): void
    {
        abort_unless(Auth::user()->can(Permission::ManageUsers->value), 403);

        $this->validate([
            'salespersonId' => ['required', Rule::in($this->salespeople()->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], ['reason.min' => 'Give a short reason (at least 10 characters) so the change can be understood later.'], ['salespersonId' => 'salesperson']);

        $onboardings = Onboarding::query()->unattributed()->whereIn('id', $this->assigningIds)->get();
        $salesperson = User::findOrFail($this->salespersonId);

        foreach ($onboardings as $onboarding) {
            $assignOnboarding->handle($onboarding->fresh(), $salesperson, Auth::user(), $this->reason);
        }

        $this->showAssign = false;
        $this->selected = [];
        $this->assigningIds = [];
        $this->dispatch('toast', message: $onboardings->count() === 1
            ? "{$onboardings->first()->property_name} is now credited to {$salesperson->name}."
            : "{$onboardings->count()} properties are now credited to {$salesperson->name}.");
    }

    public function render(): View
    {
        return view('livewire.onboardings.unattributed', [
            'onboardings' => Onboarding::query()->unattributed()->latest('submitted_at')->paginate(20),
            'salespeople' => $this->salespeople(),
            'assigning' => Onboarding::query()->whereIn('id', $this->assigningIds)->get(),
        ]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function openFor(array $ids): void
    {
        $this->resetValidation();
        $this->assigningIds = $ids;
        $this->salespersonId = '';
        $this->reason = '';
        $this->showAssign = true;
    }

    /**
     * @return Collection<int, User>
     */
    private function salespeople(): Collection
    {
        return User::query()->active()->sellers()->orderBy('name')->get(['id', 'name', 'region']);
    }
}
