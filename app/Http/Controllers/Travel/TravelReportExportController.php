<?php

namespace App\Http\Controllers\Travel;

use App\Exports\TravelReportExport;
use App\Http\Controllers\Controller;
use App\Livewire\Travel\Reports;
use App\Support\Travel\TravelReport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TravelReportExportController extends Controller
{
    /**
     * Download the Travel report (every section) as Excel, scoped like the page.
     */
    public function __invoke(Request $request): BinaryFileResponse
    {
        $viewer = $request->user();
        abort_unless(Reports::canOpen($viewer), 403);

        [$from, $to] = Reports::range((string) $request->query('period', 'month'), (string) $request->query('from', ''), (string) $request->query('to', ''));
        $salesperson = Reports::seesEveryone($viewer) && $request->filled('salesperson') ? (int) $request->query('salesperson') : null;

        return Excel::download(
            new TravelReportExport(TravelReport::for($viewer, $from, $to, $salesperson)),
            'tourlast-travel-report-'.$from->format('Y-m-d').'-to-'.$to->format('Y-m-d').'.xlsx',
        );
    }
}
