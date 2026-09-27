<?php

namespace App\Livewire\Team;

use App\Enums\Permission;
use App\Models\Onboarding;
use App\Models\Target;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Read-only overview of the targets salespeople set for themselves.
 */
#[Title('Targets')]
class Targets extends Component
{
    #[Url]
    public string $month = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ViewTeamPerformance->value), 403);
        $this->month = $this->validMonth($this->month)->format('Y-m');
    }

    public function render(): View
    {
        $month = $this->validMonth($this->month);
        $targets = Target::query()->whereDate('month', $month->toDateString())->get()->keyBy('user_id');
        $onboarded = Onboarding::query()
            ->creditedBetween($month, $month->endOfMonth())
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $rows = User::query()->active()->sellers()->orderBy('name')->get()->map(fn (User $user): array => [
            'user' => $user,
            'target' => $targets[$user->id] ?? null,
            'onboarded' => (int) ($onboarded[$user->id] ?? 0),
        ]);

        return view('livewire.team.targets', [
            'monthDate' => $month,
            'rows' => $rows,
            'locked' => Target::isLockedFor($month),
            'lockDate' => Target::lockDateFor($month),
            'missing' => $rows->whereNull('target')->count(),
            'monthOptions' => collect(range(-5, 1))->mapWithKeys(fn (int $offset): array => [
                CarbonImmutable::now()->startOfMonth()->addMonths($offset)->format('Y-m') => CarbonImmutable::now()->startOfMonth()->addMonths($offset)->format('F Y'),
            ])->reverse(),
        ]);
    }

    private function validMonth(string $value): CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}$/', $value)
            ? CarbonImmutable::createFromFormat('Y-m', $value)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();
    }
}
