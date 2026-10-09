<?php

namespace App\Livewire\Travel;

use App\Actions\Travel\SetTravelTarget;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Travel\TravelTargetMetric;
use App\Models\TravelTarget;
use App\Models\User;
use App\Support\Travel\TravelSalesMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Sales Admin sets each travel salesperson's monthly targets (flight and
 * tour bookings and revenue) and sees progress against them.
 */
#[Title('Travel targets')]
class Targets extends Component
{
    #[Url]
    public string $month = '';

    /** @var array<int, array<string, string>> user id => metric => value */
    public array $values = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can(Permission::ManageTravelTargets->value), 403);
        $this->month = $this->monthDate()->format('Y-m');
        $this->loadValues();
    }

    public function updatedMonth(): void
    {
        $this->month = $this->monthDate()->format('Y-m');
        $this->loadValues();
    }

    public function save(int $userId, SetTravelTarget $setTarget): void
    {
        $salesperson = User::query()->role(Role::TravelSalesperson->value)->findOrFail($userId);

        $this->validate([
            "values.{$userId}.*" => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ], [], ["values.{$userId}.*" => 'target']);

        foreach (TravelTargetMetric::cases() as $metric) {
            $raw = trim((string) ($this->values[$userId][$metric->value] ?? ''));
            $setTarget->handle(Auth::user(), $salesperson, $this->monthDate(), $metric, $raw === '' ? null : (int) $raw);
        }

        $this->dispatch('toast', message: "Targets saved for {$salesperson->name}.");
    }

    public function render(): View
    {
        $month = $this->monthDate();
        $people = User::query()->active()->role(Role::TravelSalesperson->value)->orderBy('name')->get();
        $actuals = TravelSalesMetrics::bySalesperson($month, $month->endOfMonth(), $people->modelKeys());
        $editable = $month->gte(CarbonImmutable::now()->startOfMonth()->subMonth());

        return view('livewire.travel.targets', [
            'monthDate' => $month,
            'people' => $people,
            'actuals' => $actuals,
            'metrics' => TravelTargetMetric::cases(),
            'editable' => $editable,
            'monthOptions' => collect(range(-6, 2))->mapWithKeys(fn (int $offset): array => [
                CarbonImmutable::now()->startOfMonth()->addMonths($offset)->format('Y-m') => CarbonImmutable::now()->startOfMonth()->addMonths($offset)->format('F Y'),
            ])->reverse(),
        ]);
    }

    private function loadValues(): void
    {
        $targets = TravelTarget::query()->whereDate('month', $this->monthDate()->toDateString())->get();
        $this->values = [];

        foreach ($targets as $target) {
            $this->values[$target->user_id][$target->metric->value] = (string) $target->target_value;
        }
    }

    private function monthDate(): CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}$/', $this->month)
            ? CarbonImmutable::createFromFormat('Y-m', $this->month)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();
    }
}
