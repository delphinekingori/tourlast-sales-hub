<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Travel\Reviews\RecordTripReview;
use App\Http\Resources\V1\TripReviewResource;
use App\Models\PackageBooking;
use App\Models\TripReview;
use App\Support\Travel\TravelAccess;
use App\Support\Travel\TripReviewFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Client reviews of completed trips. Reviews are shared Travel Sales data (as
 * in the Hub, hidden ones are only listed for Travel managers); recording one
 * follows the booking rules in RecordTripReview.
 */
class TravelReviewController extends ApiController
{
    private const With = ['booking:id,reference', 'package:id,name', 'provider:id,name', 'client:id,name', 'recorder:id,name,avatar_path'];

    /**
     * GET /travel/reviews — Reviews (?provider_id, package_id, rating 1-5 or "low", from, to).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $viewer = $this->user($request);
        TravelAccess::abortUnlessWorks($viewer);

        return TripReviewResource::collection(
            TripReviewFilters::fromArray($request->query())->query($viewer)->with(self::With)->paginate($this->perPage($request)),
        );
    }

    /**
     * GET /travel/reviews/{id} — One review.
     */
    public function show(Request $request, int $review): TripReviewResource
    {
        $viewer = $this->user($request);
        TravelAccess::abortUnlessWorks($viewer);

        return new TripReviewResource(TripReview::query()
            ->when(! TravelAccess::managesAll($viewer), fn (Builder $query) => $query->visible())
            ->with(self::With)->findOrFail($review));
    }

    /**
     * POST /travel/bookings/{id}/review — Record the client's review of a completed booking (rating, source, comment, rating_service, rating_guide, rating_value, rating_safety).
     */
    public function store(Request $request, int $booking, RecordTripReview $record): JsonResponse
    {
        $viewer = $this->user($request);
        abort_unless(TravelAccess::works($viewer), 403, 'Your account is not allowed to do this.');

        $review = $record->handle($viewer, PackageBooking::query()->visibleTo($viewer)->findOrFail($booking), $request->all());

        return (new TripReviewResource($review->load(self::With)))->response()->setStatusCode(201);
    }
}
