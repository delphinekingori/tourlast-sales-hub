<?php

namespace App\Http\Controllers\Travel;

use App\Exports\TripReviewsExport;
use App\Http\Controllers\Controller;
use App\Support\Travel\TravelAccess;
use App\Support\Travel\TripReviewFilters;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TripReviewExportController extends Controller
{
    /**
     * Download the filtered reviews list as Excel.
     */
    public function __invoke(Request $request): BinaryFileResponse
    {
        TravelAccess::abortUnlessWorks($request->user());

        return Excel::download(
            new TripReviewsExport(TripReviewFilters::fromArray($request->query()), $request->user()),
            'tourlast-trip-reviews-'.now()->format('Y-m-d').'.xlsx',
        );
    }
}
