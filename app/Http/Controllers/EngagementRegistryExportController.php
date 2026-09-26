<?php

namespace App\Http\Controllers;

use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Exports\EngagementRegistryExport;
use App\Models\PropertyEngagement;
use App\Support\EngagementRegistryFilters;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EngagementRegistryExportController extends Controller
{
    /**
     * Download the filtered registry as an Excel file.
     */
    public function excel(Request $request): BinaryFileResponse
    {
        Gate::authorize('export', PropertyEngagement::class);

        $filters = EngagementRegistryFilters::fromArray($request->query());

        return Excel::download(new EngagementRegistryExport($filters), 'tourlast-engagement-registry-'.now()->format('Y-m-d').'.xlsx');
    }

    /**
     * Management report of properties engaged in a period (this month by default).
     */
    public function pdf(Request $request): Response
    {
        Gate::authorize('export', PropertyEngagement::class);

        $filters = EngagementRegistryFilters::fromArray($request->query());
        $from = $this->date($request->query('from')) ?? CarbonImmutable::now()->startOfMonth();
        $to = $this->date($request->query('to')) ?? CarbonImmutable::now()->endOfMonth();

        $engagements = $filters->query()
            ->reorder()
            ->where('first_engaged_on', '<=', $to->toDateString())
            ->where(fn (Builder $query) => $query
                ->where('first_engaged_on', '>=', $from->toDateString())
                ->orWhere('last_engaged_on', '>=', $from->toDateString()))
            ->orderBy('name')
            ->get();

        $lost = [EngagementStatus::Lost, EngagementStatus::Rejected];
        $isOnboarding = fn (PropertyEngagement $engagement): bool => $engagement->stage->isOnboarding() && $engagement->status->isOpen();

        $summary = [
            'Total properties engaged' => $engagements->count(),
            'Newly engaged' => $engagements->filter(fn ($e) => $e->first_engaged_on->betweenIncluded($from->startOfDay(), $to->endOfDay()))->count(),
            'Currently active' => $engagements->filter(fn ($e) => $e->status->isOpen())->count(),
            'In proposal' => $engagements->where('stage', EngagementStage::ProposalSent)->count(),
            'In negotiation' => $engagements->where('stage', EngagementStage::Negotiation)->count(),
            'Onboarding' => $engagements->filter($isOnboarding)->count(),
            'Stalled' => $engagements->where('status', EngagementStatus::Stalled)->count(),
            'Lost' => $engagements->filter(fn ($e) => in_array($e->status, $lost, true))->count(),
            'Onboarded' => $engagements->where('stage', EngagementStage::Live)->count(),
        ];

        $bySalesperson = $engagements
            ->groupBy(fn ($e) => $e->salesRep?->name ?? 'Unassigned')
            ->map(fn ($group) => [
                'engaged' => $group->count(),
                'onboarding' => $group->filter($isOnboarding)->count(),
                'active' => $group->filter(fn ($e) => $e->status->isOpen())->count(),
                'lost' => $group->filter(fn ($e) => in_array($e->status, $lost, true))->count(),
                'live' => $group->where('stage', EngagementStage::Live)->count(),
            ])
            ->sortByDesc('engaged');

        $pdf = Pdf::loadView('reports.engagement-registry', [
            'from' => $from,
            'to' => $to,
            'engagements' => $engagements,
            'summary' => $summary,
            'bySalesperson' => $bySalesperson,
            'byType' => $engagements->countBy(fn ($e) => $e->propertyTypeLabel())->sortDesc(),
            'generatedBy' => $request->user()->name,
            'logo' => base64_encode((string) file_get_contents(public_path('images/tourlast-logo.png'))),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('tourlast-property-engagement-report-'.$from->format('Y-m-d').'.pdf');
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return filled($value) && strtotime((string) $value) ? CarbonImmutable::parse($value) : null;
    }
}
