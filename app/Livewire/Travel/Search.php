<?php

namespace App\Livewire\Travel;

use App\Support\Travel\TravelSearch;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Global Travel Sales search: providers, contracts, packages, bookings,
 * clients, payments, flights, drivers, guides and influencer codes.
 */
#[Title('Travel search')]
class Search extends Component
{
    #[Url]
    public string $q = '';

    public function mount(): void
    {
        abort_unless(TravelSearch::canUse(Auth::user()), 403);
    }

    public function render(): View
    {
        $groups = (new TravelSearch(Auth::user()))->run($this->q);

        return view('livewire.travel.search', [
            'groups' => $groups,
            'total' => array_sum(array_column($groups, 'total')),
            'tooShort' => mb_strlen(trim($this->q)) < 2,
        ]);
    }
}
