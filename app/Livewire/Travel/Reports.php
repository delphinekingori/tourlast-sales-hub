<?php

namespace App\Livewire\Travel;

use App\Enums\Role;
use App\Models\User;
use App\Support\Travel\TravelAccess;
use App\Support\Travel\TravelReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Travel reports: flights, tours, providers, approvals and salespeople for a
 * period, with an Excel download of every section.
 */
#[Title('Travel reports')]
class Reports extends Component
{
    public const Tabs = ['flights' => 'Flights', 'tours' => 'Tours & experiences', 'providers' => 'Providers', 'approvals' => 'Approvals', 'salespeople' => 'Salespeople'];

    public const Periods = ['month' => 'This month', 'last-month' => 'Last month', 'quarter' => 'This quarter', 'year' => 'This year', 'custom' => 'Custom'];

    #[Url]
    public string $tab = 'flights';

    #[Url]
    public string $period = 'month';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $salesperson = '';

    public function mount(): void
    {
        abort_unless(self::canOpen(Auth::user()), 403);
        $this->tab = array_key_exists($this->tab, self::Tabs) ? $this->tab : 'flights';
        $this->period = array_key_exists($this->period, self::Periods) ? $this->period : 'month';
    }

    public static function canOpen(User $user): bool
    {
        return TravelAccess::works($user) || TravelAccess::seesFinancials($user);
    }

    public static function seesEveryone(User $user): bool
    {
        return TravelAccess::managesAll($user) || TravelAccess::seesFinancials($user);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(string $period, string $from, string $to): array
    {
        $now = CarbonImmutable::now();
        $valid = fn (string $date): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);

        return match ($period) {
            'last-month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
            'year' => [$now->startOfYear(), $now->endOfYear()],
            'custom' => [$valid($from) ? CarbonImmutable::parse($from) : $now->startOfMonth(), $valid($to) ? CarbonImmutable::parse($to) : $now->endOfMonth()],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };
    }

    public function render(): View
    {
        $viewer = Auth::user();
        [$from, $to] = self::range($this->period, $this->from, $this->to);
        $everyone = self::seesEveryone($viewer);
        $report = TravelReport::for($viewer, $from, $to, $everyone && $this->salesperson !== '' ? (int) $this->salesperson : null);

        return view('livewire.travel.reports', [
            'report' => $report,
            'rangeLabel' => $from->format('j M Y').' – '.$to->format('j M Y'),
            'everyone' => $everyone,
            'salespeople' => $everyone ? User::query()->role(Role::TravelSalesperson->value)->orderBy('name')->get(['id', 'name']) : collect(),
            'exportQuery' => array_filter(['period' => $this->period, 'from' => $this->from, 'to' => $this->to, 'salesperson' => $this->salesperson]),
        ]);
    }
}
