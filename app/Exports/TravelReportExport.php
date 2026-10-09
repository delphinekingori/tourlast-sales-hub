<?php

namespace App\Exports;

use App\Support\Travel\TravelReport;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The Travel report as one workbook, one sheet per section.
 */
class TravelReportExport implements Export, WithMultipleSheets
{
    public function __construct(private TravelReport $report) {}

    /**
     * @return list<TravelReportSheet>
     */
    public function sheets(): array
    {
        $summary = fn (array $figures): array => array_map(fn ($label, $value) => ['Measure' => $label, 'Value' => $value], array_keys($figures), $figures);

        return [
            new TravelReportSheet('Flights', $summary($this->report->flightSummary())),
            new TravelReportSheet('Flight routes', $this->report->routes(100)),
            new TravelReportSheet('Airlines', $this->report->airlines()),
            new TravelReportSheet('Flight refunds', $this->report->flightRefunds()),
            new TravelReportSheet('Tours', $summary($this->report->tourSummary())),
            new TravelReportSheet('Packages', $this->report->packagePerformance(500)),
            new TravelReportSheet('Destinations', $this->report->destinations()),
            new TravelReportSheet('Providers', $this->report->providerPerformance()),
            new TravelReportSheet('Approvals', $summary($this->report->approvalSummary())),
            new TravelReportSheet('Salespeople', $this->report->salespeople()),
        ];
    }
}
