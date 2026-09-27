<?php

namespace App\Livewire\People;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A colleague's profile, read-only.
 */
class Show extends Component
{
    #[Locked]
    public int $userId;

    public function mount(User $user): void
    {
        abort_unless(Auth::user()->can(Permission::ViewPresence->value) || $user->is(Auth::user()), 403);
        $this->userId = $user->id;
    }

    public function render(): View
    {
        $person = User::with(['roles', 'referralCode', 'statusChanges.changer:id,name'])->findOrFail($this->userId);

        return view('livewire.people.show', [
            'person' => $person,
            'canSeeEarnings' => Auth::user()->can('view-earnings', $person) && $person->role()?->earnsReferrals(),
            'canSeePerformance' => Auth::user()->can(Permission::ViewTeamPerformance->value) && $person->role()?->earnsReferrals(),
        ])->title($person->name);
    }
}
