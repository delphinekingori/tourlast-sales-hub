<?php

namespace App\Livewire\People;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Staff directory with profiles and online status, for admins, Sales Managers, HR and Finance.
 */
#[Title('People')]
class Index extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $role = '';

    #[Url]
    public bool $onlineOnly = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ViewPresence->value), 403);
    }

    public function render(): View
    {
        $people = User::query()
            ->active()
            ->with('roles')
            ->when($this->role !== '', fn ($query) => $query->role($this->role))
            ->when($this->onlineOnly, fn ($query) => $query->where('last_seen_at', '>', now()->subMinutes(5)))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('job_title', 'like', "%{$this->search}%")
                ->orWhere('region', 'like', "%{$this->search}%")))
            ->orderByRaw('case when last_seen_at > ? then 0 else 1 end', [now()->subMinutes(5)])
            ->orderBy('name')
            ->get();

        return view('livewire.people.index', [
            'people' => $people,
            'onlineCount' => User::query()->active()->where('last_seen_at', '>', now()->subMinutes(5))->count(),
            'roles' => Role::cases(),
        ]);
    }
}
