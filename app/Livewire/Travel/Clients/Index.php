<?php

namespace App\Livewire\Travel\Clients;

use App\Enums\Travel\TravelBookingStatus;
use App\Models\PackageBooking;
use App\Models\TravelClient;
use App\Models\User;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Package clients with their bookings. Travel salespeople see the clients
 * they have booked (or who booked their packages); managers and Accounts
 * see everyone.
 */
#[Title('Clients')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $showClient = false;

    #[Locked]
    public ?int $clientId = null;

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless(TravelAccess::works($user) || TravelAccess::handlesPayments($user), 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function open(int $id): void
    {
        $this->clientId = $this->clients(Auth::user())->findOrFail($id)->id;
        $this->showClient = true;
    }

    public function render(): View
    {
        $user = Auth::user();
        $counted = [TravelBookingStatus::Pending, TravelBookingStatus::Confirmed, TravelBookingStatus::Completed];
        $visible = fn (Builder $bookings) => $bookings->visibleTo($user);

        $clients = $this->clients($user)
            ->search($this->search)
            ->withCount(['bookings as bookings_count' => $visible])
            ->withSum(['bookings as total_spent' => fn (Builder $bookings) => $visible($bookings)->whereIn('status', $counted)], 'amount_paid')
            ->withMax(['bookings as last_booked_at' => $visible], 'created_at')
            ->orderBy('name')
            ->paginate(25);

        $client = $this->showClient && $this->clientId ? TravelClient::query()->find($this->clientId) : null;

        return view('livewire.travel.clients.index', [
            'clients' => $clients,
            'client' => $client,
            'clientBookings' => $client
                ? PackageBooking::query()->visibleTo($user)->where('travel_client_id', $client->id)->with(['package:id,name', 'departure:id,starts_on,ends_on'])->latest()->get()
                : collect(),
        ]);
    }

    /**
     * @return Builder<TravelClient>
     */
    private function clients(User $user): Builder
    {
        return TravelClient::query()->when(
            ! TravelAccess::managesAll($user) && ! TravelAccess::handlesPayments($user),
            fn (Builder $query) => $query->whereHas('bookings', fn (Builder $bookings) => $bookings->visibleTo($user)),
        );
    }
}
