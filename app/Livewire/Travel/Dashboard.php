<?php

namespace App\Livewire\Travel;

use App\Models\User;
use App\Support\Travel\TravelAccess;
use App\Support\Travel\TravelDashboardData;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Travel Sales home: flights and tours KPIs, my sales actions, targets and
 * alerts. Travel managers can switch between their own figures and the
 * whole travel team.
 */
#[Title('Travel dashboard')]
class Dashboard extends Component
{
    /** 'mine' or 'team' (Travel managers only). */
    #[Url]
    public string $scope = '';

    public function mount(): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());

        if (! in_array($this->scope, ['mine', 'team'], true)) {
            $this->scope = $this->managesAll() && ! Auth::user()->isTravelSalesperson() ? 'team' : 'mine';
        }

        if (! $this->managesAll()) {
            $this->scope = 'mine';
        }
    }

    public function render(): View
    {
        /** @var User $viewer */
        $viewer = Auth::user();
        $team = $this->scope === 'team' && $this->managesAll();
        $data = new TravelDashboardData($team ? null : $viewer->id);

        return view('livewire.travel.dashboard', [
            'team' => $team,
            'managesAll' => $this->managesAll(),
            'seesMoney' => TravelAccess::seesFinancials($viewer),
            'flights' => $data->flightKpis(TravelAccess::seesFinancials($viewer)),
            'tours' => $data->tourKpis(),
            'trend' => $data->salesTrend(),
            'targets' => $data->targets(),
            'actions' => $data->salesActions($viewer),
            'lowAvailability' => $data->lowAvailability(),
            'expiringContracts' => $data->expiringContracts(),
            'approvals' => $team ? $data->approvals() : null,
            'salespeople' => $team ? TravelDashboardData::salespeople() : collect(),
        ]);
    }

    private function managesAll(): bool
    {
        return TravelAccess::managesAll(Auth::user());
    }
}
