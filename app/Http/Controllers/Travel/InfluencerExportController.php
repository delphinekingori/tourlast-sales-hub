<?php

namespace App\Http\Controllers\Travel;

use App\Exports\InfluencerCodesExport;
use App\Http\Controllers\Controller;
use App\Support\Travel\InfluencerAccess;
use App\Support\Travel\InfluencerCodeFilters;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InfluencerExportController extends Controller
{
    /**
     * Download the filtered influencer codes table as Excel (only the codes
     * the person may see).
     */
    public function __invoke(Request $request): BinaryFileResponse
    {
        InfluencerAccess::abortUnlessCanView($request->user());

        return Excel::download(
            new InfluencerCodesExport(InfluencerCodeFilters::fromArray($request->query()), $request->user()),
            'tourlast-influencer-codes-'.now()->format('Y-m-d').'.xlsx',
        );
    }
}
