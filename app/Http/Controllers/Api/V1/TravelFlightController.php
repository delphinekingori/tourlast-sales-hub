<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\FlightBookingResource;
use App\Models\FlightBooking;
use App\Support\Travel\FlightSyncStatus;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Flight bookings, read-only: Tourlast Flights Super Admin is the source of
 * truth and the Hub never changes a booking. Mirrors App\Livewire\Travel\Flights.
 */
class TravelFlightController extends ApiController
{
    /**
     * GET /travel/flights — Flight bookings (?view=bookings|upcoming|cancellations|refunds, q, status, airline, from, to, mine).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $viewer = $this->user($request);
        TravelAccess::abortUnlessWorks($viewer);

        $view = in_array($request->query('view'), ['bookings', 'upcoming', 'cancellations', 'refunds'], true) ? (string) $request->query('view') : 'bookings';
        $status = (string) $request->query('status', '');
        $date = fn (string $key): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) ? (string) $request->query($key) : null;

        $query = FlightBooking::query()
            ->with(['salesperson:id,name,avatar_path', 'passengers'])
            ->search((string) $request->query('q', ''))
            ->when($status !== '', fn (Builder $query) => $query->where($view === 'refunds' ? 'refund_status' : 'booking_status', mb_strtolower($status)))
            ->when($request->filled('airline'), fn (Builder $query) => $query->where('airline_code', strtoupper((string) $request->query('airline'))))
            ->when($date('from'), fn (Builder $query, string $from) => $query->where('booked_at', '>=', $from.' 00:00:00'))
            ->when($date('to'), fn (Builder $query, string $to) => $query->where('booked_at', '<=', $to.' 23:59:59'))
            ->when($request->boolean('mine'), fn (Builder $query) => $query->where('salesperson_id', $viewer->id))
            ->when($request->filled('salesperson') && TravelAccess::managesAll($viewer), fn (Builder $query) => $query->where('salesperson_id', $request->integer('salesperson')));

        $query = match ($view) {
            'upcoming' => $query->upcoming()->orderBy('departure_at'),
            'cancellations' => $query->where(fn (Builder $query) => $query->whereNotNull('cancelled_at')->orWhereNotNull('cancellation_status'))->orderByDesc('cancelled_at'),
            'refunds' => $query->whereNotNull('refund_status')->orderByDesc('refund_requested_at'),
            default => $query->orderByDesc('booked_at')->orderByDesc('id'),
        };

        $sync = new FlightSyncStatus;

        return FlightBookingResource::collection($query->paginate($this->perPage($request)))->additional(['meta' => [
            'view' => $view,
            'source' => config('travel.flights.source'),
            'last_synced_at' => $sync->lastSyncedAt()?->toIso8601String(),
            'stale' => $sync->isStale(),
        ]]);
    }

    /**
     * GET /travel/flights/{id} — One flight booking with segments and passengers.
     */
    public function show(Request $request, int $booking): FlightBookingResource
    {
        TravelAccess::abortUnlessWorks($this->user($request));

        return new FlightBookingResource(FlightBooking::query()->with(['salesperson:id,name,avatar_path', 'segments', 'passengers'])->findOrFail($booking));
    }
}
