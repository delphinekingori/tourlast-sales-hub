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

    #[Locked]
    public ?int $assigningId = null;

    public string $salespersonId = '';

    public string $reason = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ManageUsers->value), 403);
    }

    public function openAssign(int $onboardingId): void
    {
        $this->resetValidation();
        $this->assigningId = Onboarding::query()->unattributed()->findOrFail($onboardingId)->id;
        $this->salespersonId = '';
        $this->reason = '';
        $this->showAssign = true;
    }

    public function assign(AssignOnboarding $assignOnboarding): void
    {
        abort_unless(Auth::user()->can(Permission::ManageUsers->value), 403);

        $this->validate([
            'salespersonId' => ['required', Rule::in($this->salespeople()->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], ['reason.min' => 'Give a short reason (at least 10 characters) so the change can be understood later.'], ['salespersonId' => 'salesperson']);

        $onboarding = Onboarding::query()->unattributed()->findOrFail($this->assigningId);
        $salesperson = User::findOrFail($this->salespersonId);

        $assignOnboarding->handle($onboarding, $salesperson, Auth::user(), $this->reason);

        $this->showAssign = false;
        $this->dispatch('toast', message: "{$onboarding->property_name} is now credited to {$salesperson->name}.");
    }

    public function render(): View
    {
        return view('livewire.onboardings.unattributed', [
            'onboardings' => Onboarding::query()->unattributed()->latest('submitted_at')->paginate(20),
            'salespeople' => $this->salespeople(),
            'assigning' => $this->assigningId ? Onboarding::find($this->assigningId) : null,
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function salespeople(): Collection
    {
        return User::query()->active()->sellers()->orderBy('name')->get(['id', 'name', 'region']);
    }
}
