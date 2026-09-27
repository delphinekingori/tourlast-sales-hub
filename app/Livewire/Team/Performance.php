<?php

namespace App\Livewire\Team;

use App\Enums\Permission;
use App\Incentives\MonthlyEarnings;
use App\Models\User;
use App\Support\Period;
use App\Support\SalesMetrics;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Team Performance')]
class Performance extends Component
{
    #[Url]
    public string $period = 'month';

    #[Url]
    public string $region = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ViewTeamPerformance->value), 403);
        $this->period = array_key_exists($this->period, Period::options()) ? $this->period : 'month';
    }

    public function render(SalesMetrics $metrics, MonthlyEarnings $monthlyEarnings): View
    {
        $period = Period::named($this->period);
        $rows = $metrics->team($period, $this->region ?: null);
        $canSeePay = Auth::user()->can(Permission::ViewTeamEarnings->value) && $period->key === 'month';
        $pay = $canSeePay
            ? $rows->mapWithKeys(fn (array $row): array => [$row['user']->id => $monthlyEarnings->hasAgreement($row['user'], $period->from) ? $monthlyEarnings->for($row['user'], $period->from)->total() : null])
            : collect();

        return view('livewire.team.performance', [
            'range' => $period,
            'canSeePay' => $canSeePay,
            'pay' => $pay,
            'rows' => $rows,
            'totals' => [
                'onboarded' => $rows->sum(fn ($row) => $row['metrics']['onboarded']),
                'points' => $rows->sum(fn ($row) => $row['metrics']['points']),
                'target' => $rows->sum(fn ($row) => $row['metrics']['target'] ?? 0),
                'awaiting' => $rows->sum(fn ($row) => $row['metrics']['awaiting']),
                'clicks' => $rows->sum(fn ($row) => $row['metrics']['clicks']),
                'needsAttention' => $rows->filter(fn ($row) => in_array($row['status']['tone'], ['danger', 'warning'], true))->count(),
            ],
            'regions' => User::query()->active()->sellers()->whereNotNull('region')->distinct()->orderBy('region')->pluck('region'),
        ]);
    }
}
