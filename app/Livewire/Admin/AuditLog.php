<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only, system-wide audit log: who did what, when, with before and
 * after values. Nothing here can be edited or deleted.
 */
#[Title('Audit log')]
class AuditLog extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $area = '';

    #[Url]
    public string $user = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ViewAuditLog->value), 403);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $events = AuditEvent::query()
            ->with('user:id,name')
            ->when($this->search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('summary', 'like', "%{$this->search}%")
                ->orWhere('action', 'like', "%{$this->search}%")))
            ->when($this->area !== '', fn (Builder $query) => $query->where('action', 'like', $this->area.'.%'))
            ->when($this->user !== '', fn (Builder $query) => $query->where('user_id', (int) $this->user))
            ->when($this->validDate($this->from), fn (Builder $query) => $query->where('created_at', '>=', $this->from.' 00:00:00'))
            ->when($this->validDate($this->to), fn (Builder $query) => $query->where('created_at', '<=', $this->to.' 23:59:59'))
            ->latest('created_at')
            ->latest('id')
            ->paginate(30);

        return view('livewire.admin.audit-log', [
            'events' => $events,
            'areas' => AuditEvent::query()
                ->selectRaw('distinct action')
                ->pluck('action')
                ->map(fn (string $action): string => explode('.', $action)[0])
                ->unique()
                ->sort()
                ->values(),
            'people' => User::query()->whereIn('id', AuditEvent::query()->whereNotNull('user_id')->select('user_id'))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * "package.approved" → "Package approved".
     */
    public static function actionLabel(string $action): string
    {
        return ucfirst(str_replace(['.', '_'], ' ', $action));
    }

    private function validDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
