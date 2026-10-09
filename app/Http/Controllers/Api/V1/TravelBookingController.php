<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Travel\Bookings\ChangeBookingStatus;
use App\Actions\Travel\Bookings\CreatePackageBooking;
use App\Actions\Travel\Payments\RequestMpesaPayment;
use App\Http\Resources\V1\PackageBookingResource;
use App\Http\Resources\V1\PackageDepartureResource;
use App\Http\Resources\V1\TravelPaymentResource;
use App\Models\PackageBooking;
use App\Models\PackageDeparture;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Package inventory (departures and slots), bookings and M-Pesa payment
 * requests. Travel salespeople see their own bookings and bookings on their
 * own packages; overbooking, client matching and payment rules live in the
 * shared actions.
 */
class TravelBookingController extends ApiController
{
    /**
     * GET /travel/departures — Departures with sold, reserved and available slots (?package_id, from, to).
     */
    public function departures(Request $request): AnonymousResourceCollection
    {
        $viewer = $this->user($request);
        TravelAccess::abortUnlessWorks($viewer);
        $date = fn (string $key): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) ? (string) $request->query($key) : null;

        $departures = PackageDeparture::query()
            ->with(['package:id,name,reference,owner_id', 'driver:id,name', 'guide:id,name'])
            ->withSlotCounts()
            ->when(! TravelAccess::managesAll($viewer), fn (Builder $query) => $query->whereHas('package', fn (Builder $package) => $package->where('owner_id', $viewer->id)))
            ->when($request->filled('package_id'), fn (Builder $query) => $query->where('package_id', $request->integer('package_id')))
            ->when($date('from'), fn (Builder $query, string $from) => $query->whereDate('starts_on', '>=', $from))
            ->when($date('to'), fn (Builder $query, string $to) => $query->whereDate('starts_on', '<=', $to))
            ->orderBy('starts_on')
            ->paginate($this->perPage($request));

        return PackageDepartureResource::collection($departures);
    }

    /**
     * GET /travel/bookings — Package bookings you may see (?q, status, payment_status, package_id).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $viewer = $this->user($request);
        abort_unless(TravelAccess::works($viewer) || TravelAccess::handlesPayments($viewer), 403, 'Your account is not allowed to do this.');

        $bookings = PackageBooking::query()
            ->visibleTo($viewer)
            ->with(['package:id,name,reference', 'departure', 'client', 'salesperson:id,name,avatar_path', 'influencerCode:id,code'])
            ->search((string) $request->query('q', ''))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('payment_status'), fn (Builder $query) => $query->where('payment_status', (string) $request->query('payment_status')))
            ->when($request->filled('package_id'), fn (Builder $query) => $query->where('package_id', $request->integer('package_id')))
            ->latest()
            ->paginate($this->perPage($request));

        return PackageBookingResource::collection($bookings);
    }

    /**
     * GET /travel/bookings/{id} — One booking with its payments.
     */
    public function show(Request $request, int $booking): JsonResponse
    {
        $record = $this->visible($request, $booking)->load(['package:id,name,reference', 'departure', 'client', 'salesperson:id,name,avatar_path', 'influencerCode:id,code', 'payments']);

        return (new PackageBookingResource($record))->additional([
            'payments' => TravelPaymentResource::collection($record->payments)->resolve($request),
        ])->response();
    }

    /**
     * POST /travel/bookings — Book a client on a departure (slots are held while pending; overbooking is refused).
     */
    public function store(Request $request, CreatePackageBooking $createBooking): JsonResponse
    {
        $booking = $createBooking->handle($this->user($request), $request->all());

        return (new PackageBookingResource($booking->load(['package:id,name,reference', 'departure', 'client', 'salesperson:id,name,avatar_path'])))
            ->response()->setStatusCode(201);
    }

    /**
     * POST /travel/bookings/{id}/confirm — Confirm a pending booking.
     */
    public function confirm(Request $request, int $booking, ChangeBookingStatus $changeStatus): PackageBookingResource
    {
        $record = $changeStatus->confirm($this->user($request), $this->visible($request, $booking));

        return new PackageBookingResource($record->fresh()->load(['package:id,name,reference', 'departure', 'client', 'salesperson:id,name,avatar_path']));
    }

    /**
     * POST /travel/bookings/{id}/mpesa — Send an M-Pesa payment request (STK push) to the client's phone (phone, amount).
     */
    public function requestMpesa(Request $request, int $booking, RequestMpesaPayment $requestPayment): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        $payment = $requestPayment->handle($this->visible($request, $booking), $data['phone'], $data['amount'], $this->user($request));

        return (new TravelPaymentResource($payment->load('booking:id,reference')))->response()->setStatusCode(201);
    }

    private function visible(Request $request, int $booking): PackageBooking
    {
        $viewer = $this->user($request);
        abort_unless(TravelAccess::works($viewer) || TravelAccess::handlesPayments($viewer), 403, 'Your account is not allowed to do this.');

        return PackageBooking::query()->visibleTo($viewer)->findOrFail($booking);
    }
}
