<?php

namespace App\Livewire\Travel\Flights;

use App\Models\FlightBooking;
use App\Support\Travel\FlightSyncStatus;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One flight booking, read-only. Changes happen in Flights Super Admin.
 */
#[Title('Flight booking')]
class Show extends Component
{
    #[Locked]
    public int $bookingId;

    public function mount(FlightBooking $booking): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());

        $this->bookingId = $booking->id;
    }

    public function render(): View
    {
        $viewer = Auth::user();
        $booking = FlightBooking::query()
            ->with(['segments', 'passengers', 'salesperson:id,name,email', 'influencerCode.influencer:id,name'])
            ->findOrFail($this->bookingId);

        return view('livewire.travel.flights.show', [
            'booking' => $booking,
            'managesAll' => TravelAccess::managesAll($viewer),
            'seesMoney' => TravelAccess::seesFinancials($viewer),
            'seesContact' => $booking->contactVisibleTo($viewer),
            'sync' => new FlightSyncStatus,
        ])->title(($booking->booking_reference ?? $booking->external_id).' · Flight booking');
    }
}
