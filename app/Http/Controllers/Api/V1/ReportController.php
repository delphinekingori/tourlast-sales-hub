<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Objection;
use App\Enums\Permission;
use App\Http\Controllers\EngagementRegistryExportController;
use App\Http\Controllers\PartnerRegisterExportController;
use App\Livewire\Insights\MyLosses;
use App\Livewire\Insights\Objections;
use App\Models\Onboarding;
use App\Support\PartnerRegisterFilters;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Lost & objections insights, the Partner Register, and Excel/PDF exports.
 * Figures and files come from the same code as the web pages.
 */
class ReportController extends ApiController
{
    /**
     * GET /insights/objections?period=90|quarter|year|all — team view (managers).
     */
    public function objections(Request $request): JsonResponse
    {
        $this->requirePermission($request, Permission::ViewTeamPerformance, Permission::ManageEngagementRegistry);

        return $this->insights(new Objections, $request);
    }

    /**
     * GET /me/losses?period= — the salesperson's own losses.
     */
    public function myLosses(Request $request): JsonResponse
    {
        $this->requireSeller($request);

        return $this->insights(new MyLosses, $request);
    }

    /**
     * GET /partners — the Partner Register (onboarded partners by default).
     */
    public function partners(Request $request): JsonResponse
    {
        $this->requirePermission($request, Permission::ViewPartnerRegister);

        $filters = PartnerRegisterFilters::fromArray($request->query());
        $query = $filters->query();
        $page = (clone $query)->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (Onboarding $partner) => [
                'id' => $partner->id,
                'tourlast_property_id' => $partner->tourlast_property_id,
                'property_name' => $partner->property_name,
                'property_type' => $partner->property_type,
                'property_type_label' => $partner->propertyTypeLabel(),
                'location' => $partner->location,
                'contact_name' => $partner->contact_name,
                'contact_phone' => $partner->contact_phone,
                'contact_email' => $partner->contact_email,
                'status' => $partner->status->value,
                'status_label' => $partner->status->label(),
                'ref_code' => $partner->ref_code,
                'attribution' => $partner->attribution,
                'salesperson' => $partner->user ? ['id' => $partner->user->id, 'name' => $partner->user->name] : null,
                'submitted_at' => $partner->submitted_at?->toIso8601String(),
                'onboarded_at' => $partner->credited_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'filters' => $filters->toQueryString(),
                'period' => $filters->periodLabel(),
                'by_type' => (clone $query)->reorder()->selectRaw('property_type, count(*) as total')->groupBy('property_type')->pluck('total', 'property_type')->sortDesc(),
            ],
        ]);
    }

    /**
     * GET /reports/partner-register.xlsx
     */
    public function partnerRegisterExcel(Request $request, PartnerRegisterExportController $controller): BinaryFileResponse
    {
        return $controller->excel($request);
    }

    /**
     * GET /reports/partner-register.pdf
     */
    public function partnerRegisterPdf(Request $request, PartnerRegisterExportController $controller): Response
    {
        return $controller->pdf($request);
    }

    /**
     * GET /reports/registry.xlsx
     */
    public function registryExcel(Request $request, EngagementRegistryExportController $controller): BinaryFileResponse
    {
        return $controller->excel($request);
    }

    /**
     * GET /reports/registry.pdf?from=&to=
     */
    public function registryPdf(Request $request, EngagementRegistryExportController $controller): Response
    {
        return $controller->pdf($request);
    }

    /**
     * Run the insights page's own calculation and return its data as JSON.
     */
    private function insights(Objections $page, Request $request): JsonResponse
    {
        $page->period = array_key_exists((string) $request->query('period'), Objections::Periods) ? (string) $request->query('period') : 'year';
        $data = $page->render()->getData();
        $date = fn (?CarbonInterface $value) => $value?->toDateString();
        $total = max(1, (int) $data['byObjection']->sum());

        return response()->json(['data' => [
            'period' => ['key' => $page->period, 'label' => Objections::Periods[$page->period]],
            'summary' => [
                'properties_lost' => $data['losses']->count(),
                'top_objection' => $data['topObjection'] ? ['value' => $data['topObjection']->value, 'label' => $data['topObjection']->label()] : null,
                'competitor_share_percent' => $data['competitorShare'],
                'lost_without_reason' => $data['unexplained'],
                'upcoming_reengagements' => $data['upcoming']->count(),
            ],
            'by_objection' => $data['byObjection']->map(fn (int $count, string $value) => [
                'value' => $value,
                'label' => Objection::from($value)->label(),
                'count' => $count,
                'percent' => (int) round($count / $total * 100),
            ])->values(),
            'by_competitor' => $data['byCompetitor']->map(fn (int $count, string $name) => ['name' => $name, 'count' => $count])->values(),
            'by_salesperson' => $data['byRep']->map(fn (array $row, string $name) => ['name' => $name, 'count' => $row['count'], 'top_objection' => $row['top']])->values(),
            'by_property_type' => $data['byType']->map(fn (int $count, string $type) => ['label' => $type, 'count' => $count])->values(),
            'upcoming_reengagements' => $this->rows($data['upcoming'], fn (array $item) => [
                'name' => $item['name'],
                'salesperson' => $item['rep'],
                'objection' => $item['objection']?->value,
                'objection_label' => $item['objection']?->label(),
                'reengage_on' => $date($item['date']),
                'lost_on' => $date($item['lost']),
                'web_url' => $item['url'],
            ]),
            'losses' => $this->rows($data['losses'], fn (array $loss) => [
                'kind' => $loss['kind'],
                'id' => $loss['id'],
                'name' => $loss['name'],
                'property_type' => $loss['type'],
                'salesperson' => $loss['rep'],
                'objection' => $loss['objection']?->value,
                'objection_label' => $loss['objection']?->label(),
                'competitor' => $loss['competitor'],
                'notes' => $loss['notes'],
                'lost_on' => $date($loss['date']),
                'web_url' => $loss['url'],
            ]),
        ]]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $items, callable $map): array
    {
        return $items->map($map)->values()->all();
    }
}
