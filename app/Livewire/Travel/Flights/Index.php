<?php

namespace App\Livewire\Travel\Flights;

use App\Enums\Role;
use App\Models\FlightBooking;
use App\Models\User;
use App\Support\Travel\FlightSyncStatus;
use App\Support\Travel\TravelAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Flights: bookings, upcoming flights, cancellations, refunds and customers,
 * read from the Hub's copy of Flights Super Admin. Nothing here changes a
 * booking; every row links back to Flights Super Admin.
 */
#[Title('Flights')]
class Index extends Component
{
    use WithPagination;

    public const Views = [
        'bookings' => 'Bookings',
        'upcoming' => 'Upcoming flights',
        'cancellations' => 'Cancellations',
        'refunds' => 'Refunds',
        'customers' => 'Flight customers',
    ];

    private const Sorts = ['booked_at', 'departure_at', 'total_amount'];

    #[Url]
    public string $view = 'bookings';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $airline = '';

    #[Url]
    public string $origin = '';

    #[Url]
    public string $destination = '';

    #[Url]
    public string $bookedFrom = '';

    #[Url]
    public string $bookedTo = '';

    #[Url]
    public string $departFrom = '';

    #[Url]
    public string $departTo = '';

    #[Url]
    public string $salesperson = '';

    #[Url]
    public bool $mine = false;

    #[Url]
    public string $sort = 'booked_at';

    #[Url]
    public string $dir = 'desc';

    public function mount(): void
    {
        TravelAccess::abortUnlessWorks(Auth::user());

        $this->view = array_key_exists($this->view, self::Views) ? $this->view : 'bookings';
        $this->sort = in_array($this->sort, self::Sorts, true) ? $this->sort : 'booked_at';
        $this->dir = $this->dir === 'asc' ? 'asc' : 'desc';
    }

    public function updating(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function updatedView(): void
    {
        $this->view = array_key_exists($this->view, self::Views) ? $this->view : 'bookings';
    }

    public function sortBy(string $column): void
    {
        abort_unless(in_array($column, self::Sorts, true), 404);

        $this->dir = $this->sort === $column && $this->dir === 'desc' ? 'asc' : 'desc';
        $this->sort = $column;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'airline', 'origin', 'destination', 'bookedFrom', 'bookedTo', 'departFrom', 'departTo', 'salesperson', 'mine']);
        $this->resetPage();
    }

    public function render(): View
    {
        $viewer = Auth::user();
        $managesAll = TravelAccess::managesAll($viewer);

        return view('livewire.travel.flights.index', [
            'viewer' => $viewer,
            'managesAll' => $managesAll,
            'seesMoney' => TravelAccess::seesFinancials($viewer),
            'sync' => new FlightSyncStatus,
            'summary' => $this->summary(),
            'rows' => $this->rows(),
            'refundSummary' => $this->view === 'refunds' ? $this->refundSummary() : collect(),
            'airlines' => FlightBooking::query()->whereNotNull('airline_code')->distinct()->orderBy('airline_name')->pluck('airline_name', 'airline_code'),
            'statuses' => FlightBooking::query()->whereNotNull('booking_status')->distinct()->orderBy('booking_status')->pluck('booking_status'),
            'refundStatuses' => FlightBooking::query()->whereNotNull('refund_status')->distinct()->orderBy('refund_status')->pluck('refund_status'),
            'airports' => FlightBooking::query()->select('origin as code')->whereNotNull('origin')->union(FlightBooking::query()->select('destination as code')->whereNotNull('destination'))->orderBy('code')->pluck('code')->unique()->values(),
            'salespeople' => $managesAll ? User::query()->role(Role::TravelSalesperson->value)->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    private function rows(): LengthAwarePaginator
    {
        if ($this->view === 'customers') {
            return $this->customers();
        }

        $query = $this->filtered()->with(['salesperson:id,name', 'passengers:id,flight_booking_id,name,ticket_number']);

        return match ($this->view) {
            'upcoming' => $query->upcoming()->orderBy('departure_at')->paginate(50),
            'cancellations' => $query->where(fn (Builder $query) => $query->whereNotNull('cancelled_at')->orWhereNotNull('cancellation_status'))
                ->orderByDesc('cancelled_at')->paginate(25),
            'refunds' => $query->whereNotNull('refund_status')->orderByDesc('refund_requested_at')->paginate(25),
            default => $query->orderBy($this->sort, $this->dir)->orderByDesc('id')->paginate(25),
        };
    }

    /**
     * @return Builder<FlightBooking>
     */
    private function filtered(): Builder
    {
        $managesAll = TravelAccess::managesAll(Auth::user());

        return FlightBooking::query()
            ->search($this->search)
            ->when($this->status !== '', fn (Builder $query) => $this->view === 'refunds'
                ? $query->where('refund_status', $this->status)
                : $query->where('booking_status', $this->status))
            ->when($this->airline !== '', fn (Builder $query) => $query->where('airline_code', $this->airline))
            ->when($this->origin !== '', fn (Builder $query) => $query->where('origin', $this->origin))
            ->when($this->destination !== '', fn (Builder $query) => $query->where('destination', $this->destination))
            ->when($this->validDate($this->bookedFrom), fn (Builder $query) => $query->whereDate('booked_at', '>=', $this->bookedFrom))
            ->when($this->validDate($this->bookedTo), fn (Builder $query) => $query->whereDate('booked_at', '<=', $this->bookedTo))
            ->when($this->validDate($this->departFrom), fn (Builder $query) => $query->whereDate('departure_at', '>=', $this->departFrom))
            ->when($this->validDate($this->departTo), fn (Builder $query) => $query->whereDate('departure_at', '<=', $this->departTo))
            ->when($this->mine, fn (Builder $query) => $query->where('salesperson_id', Auth::id()))
            ->when($managesAll && ctype_digit($this->salesperson), fn (Builder $query) => $query->where('salesperson_id', (int) $this->salesperson));
    }

    /**
     * One row per customer (email, else phone, else name).
     */
    private function customers(): LengthAwarePaginator
    {
        $key = "coalesce(nullif(customer_email, ''), nullif(customer_phone, ''), customer_name)";

        return $this->filtered()
            ->toBase()
            ->whereNotNull(DB::raw($key))
            ->selectRaw("{$key} as customer_key")
            ->selectRaw('max(customer_name) as customer_name, max(customer_email) as customer_email, max(customer_phone) as customer_phone')
            ->selectRaw('count(*) as bookings_count, sum(case when cancelled_at is null then coalesce(total_amount, 0) else 0 end) as total_spent')
            ->selectRaw('max(booked_at) as last_booked_at')
            ->selectRaw('min(case when departure_at >= ? and cancelled_at is null then departure_at end) as next_flight_at', [now()])
            ->selectRaw('max(case when salesperson_id = ? then 1 else 0 end) as is_mine', [Auth::id()])
            ->groupBy(DB::raw($key))
            ->orderByDesc('last_booked_at')
            ->paginate(25);
    }

    /**
     * @return array<string, int|float>
     */
    private function summary(): array
    {
        $monthStart = now()->startOfMonth();
        $base = fn (): Builder => FlightBooking::query()->when($this->mine, fn (Builder $query) => $query->where('salesperson_id', Auth::id()));

        return [
            'today' => $base()->whereDate('booked_at', today())->count(),
            'month' => $base()->where('booked_at', '>=', $monthStart)->count(),
            'upcoming' => $base()->upcoming()->count(),
            'cancelled' => $base()->where('cancelled_at', '>=', $monthStart)->count(),
            'refundsPending' => $base()->whereIn('refund_status', ['pending', 'processing', 'requested'])->count(),
            'refundsCompleted' => $base()->where('refund_status', 'completed')->where('refund_completed_at', '>=', $monthStart)->count(),
            'revenue' => (float) $base()->where('booked_at', '>=', $monthStart)->whereNull('cancelled_at')->sum('total_amount'),
            'markup' => (float) $base()->where('booked_at', '>=', $monthStart)->whereNull('cancelled_at')->sum('markup_amount'),
        ];
    }

    /**
     * Refund count and amount per Flights refund status, within the filters.
     *
     * @return Collection<int, object{refund_status: string, total: int, amount: float}>
     */
    private function refundSummary(): Collection
    {
        return $this->filtered()
            ->toBase()
            ->whereNotNull('refund_status')
            ->selectRaw('refund_status, count(*) as total, sum(coalesce(refund_amount, 0)) as amount')
            ->groupBy('refund_status')
            ->orderBy('refund_status')
            ->get();
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
