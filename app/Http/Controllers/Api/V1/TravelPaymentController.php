<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\InfluencerCodeResource;
use App\Http\Resources\V1\TravelPaymentResource;
use App\Models\TravelPayment;
use App\Support\Travel\InfluencerAccess;
use App\Support\Travel\InfluencerCodeFilters;
use App\Support\Travel\PaymentAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Package payments and influencer codes (read). Accounts and Travel managers
 * see every payment; travel salespeople see payments on bookings they may
 * see. Confirming, allocating and paying commission stay in the web app.
 */
class TravelPaymentController extends ApiController
{
    /**
     * GET /travel/payments — Package payments (?status, method, unmatched=1 for Accounts).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $viewer = $this->user($request);
        abort_unless(PaymentAccess::opensPayments($viewer), 403, 'Your account is not allowed to do this.');
        $seesAll = PaymentAccess::seesAll($viewer);

        $payments = TravelPayment::query()
            ->with('booking:id,reference')
            ->when(! $seesAll, fn (Builder $query) => $query->whereHas('booking', fn (Builder $booking) => $booking->visibleTo($viewer)))
            ->when($request->boolean('unmatched') && $seesAll, fn (Builder $query) => $query->unallocated())
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('method'), fn (Builder $query) => $query->where('method', (string) $request->query('method')))
            ->latest()
            ->paginate($this->perPage($request));

        return TravelPaymentResource::collection($payments);
    }

    /**
     * GET /travel/influencer-codes — Influencer codes with commission terms and results (?q, status, applies_to).
     */
    public function influencerCodes(Request $request): AnonymousResourceCollection
    {
        $viewer = $this->user($request);
        abort_unless(InfluencerAccess::canView($viewer), 403, 'Your account is not allowed to do this.');

        $codes = InfluencerCodeFilters::fromArray([
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'applies_to' => (string) $request->query('applies_to', ''),
        ])->query($viewer)->paginate($this->perPage($request));

        return InfluencerCodeResource::collection($codes);
    }
}
