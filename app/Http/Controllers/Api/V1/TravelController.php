<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\Travel\TravelTargetMetric;
use App\Livewire\Travel\Reports;
use App\Models\FollowUp;
use App\Models\TravelTarget;
use App\Models\User;
use App\Support\Travel\TravelAccess;
use App\Support\Travel\TravelDashboardData;
use App\Support\Travel\TravelReport;
use App\Support\Travel\TravelSalesMetrics;
use App\Support\Travel\TravelSubjects;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Travel Sales overview: dashboard, reports, targets and scheduling travel
 * follow-ups. Mirrors App\Livewire\Travel\Dashboard, Reports and Targets.
 */
class TravelController extends ApiController
{
    /**
     * GET /travel/dashboard — Flights and tours figures, sales actions and alerts (?scope=team for Travel managers).
     */
    public function dashboard(Request $request): JsonResponse
    {
        $viewer = $this->user($request);
        TravelAccess::abortUnlessWorks($viewer);

        $team = $request->query('scope') === 'team' && TravelAccess::managesAll($viewer);
        $data = new TravelDashboardData($team ? null : $viewer->id);

        return response()->json(['data' => [
            'scope' => $team ? 'team' : 'mine',
            'flights' => $data->flightKpis(TravelAccess::seesFinancials($viewer)),
            'tours' => $data->tourKpis(),
            'targets' => $data->targets(),
            'sales_actions' => $data->salesActions($viewer)->map(fn (array $action) => [
                'when' => $action['when'],
                'due' => $action['sort'],
                'label' => $action['label'],
                'detail' => $action['detail'],
                'url' => $action['url'],
            ])->values(),
            'low_availability' => $data->lowAvailability()->map(fn ($departure) => [
                'departure_id' => $departure->id,
                'package' => $departure->package?->name,
                'starts_on' => $departure->starts_on->toDateString(),
                'capacity' => $departure->capacity,
                'taken' => $departure->soldSlots() + $departure->reservedSlots(),
                'status' => $departure->availabilityStatus()->value,
            ])->values(),
            'expiring_contracts' => $data->expiringContracts()->map(fn ($contract) => [
                'contract_id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'provider' => $contract->provider?->name,
                'ends_on' => $contract->ends_on?->toDateString(),
                'days_until_expiry' => $contract->daysUntilExpiry(),
            ])->values(),
            'approvals' => $team ? $data->approvals() : null,
            'salespeople' => $team ? TravelDashboardData::salespeople()->map(fn (array $row) => [
                'user' => ['id' => $row['user']->id, 'name' => $row['user']->name],
                ...collect($row)->except('user')->all(),
            ])->values() : null,
        ]]);
    }

    /**
     * GET /travel/reports — Travel report sections for a period (?period=month|last-month|quarter|year|custom&from&to&salesperson).
     */
    public function reports(Request $request): JsonResponse
    {
        $viewer = $this->user($request);
        abort_unless(Reports::canOpen($viewer), 403, 'Your account is not allowed to do this.');

        $period = (string) $request->query('period', 'month');
        [$from, $to] = Reports::range(array_key_exists($period, Reports::Periods) ? $period : 'month', (string) $request->query('from', ''), (string) $request->query('to', ''));
        $salesperson = Reports::seesEveryone($viewer) && $request->filled('salesperson') ? $request->integer('salesperson') : null;
        $report = TravelReport::for($viewer, $from, $to, $salesperson);

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'flights' => ['summary' => $report->flightSummary(), 'routes' => $report->routes(), 'airlines' => $report->airlines(), 'refunds' => $report->flightRefunds()],
            'tours' => ['summary' => $report->tourSummary(), 'packages' => $report->packagePerformance(), 'destinations' => $report->destinations()],
            'providers' => ['summary' => $report->providerSummary(), 'performance' => $report->providerPerformance()],
            'approvals' => $report->approvalSummary(),
            'salespeople' => $report->salespeople(),
        ]]);
    }

    /**
     * GET /travel/targets — Travel targets and progress for a month (?month=YYYY-MM). Own targets; Sales Admin sees everyone.
     */
    public function targets(Request $request): JsonResponse
    {
        $viewer = $this->user($request);
        $managesTargets = $viewer->can(Permission::ManageTravelTargets->value);
        abort_unless($managesTargets || $viewer->isTravelSalesperson(), 403, 'Your account is not allowed to do this.');

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? CarbonImmutable::createFromFormat('Y-m', (string) $request->query('month'))->startOfMonth() : CarbonImmutable::now()->startOfMonth();
        $people = $managesTargets
            ? User::query()->role(Role::TravelSalesperson->value)->orderBy('name')->get(['id', 'name'])
            : collect([$viewer]);
        $actuals = TravelSalesMetrics::bySalesperson($month, $month->endOfMonth(), $people->modelKeys());
        $targets = TravelTarget::query()->whereIn('user_id', $people->modelKeys())->whereDate('month', $month->toDateString())->get()->groupBy('user_id');

        return response()->json(['data' => $people->map(fn (User $user) => [
            'user' => ['id' => $user->id, 'name' => $user->name],
            'month' => $month->format('Y-m'),
            'metrics' => array_map(fn (TravelTargetMetric $metric) => [
                'metric' => $metric->value,
                'label' => $metric->label(),
                'target' => $targets->get($user->id)?->firstWhere('metric', $metric)?->target_value,
                'actual' => (float) ($actuals[$user->id][$metric->value] ?? 0),
            ], TravelTargetMetric::cases()),
        ])->values()]);
    }

    /**
     * POST /travel/schedule — Schedule a follow-up, meeting or check-in about a travel record (subject "package:12", "booking:5"…).
     */
    public function schedule(Request $request): JsonResponse
    {
        $viewer = $this->user($request);
        abort_unless($viewer->isTravelSalesperson(), 403, 'Only travel salespeople schedule travel follow-ups.');

        $data = $request->validate([
            'subject' => ['required', 'string'],
            'type' => ['required', Rule::enum(ActivityType::class)->only(ActivityType::forTravel())],
            'task' => ['required', 'string', 'max:190'],
            'due_at' => ['required', 'date', 'after_or_equal:today'],
            'has_time' => ['sometimes', 'boolean'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'location' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $subject = TravelSubjects::find($data['subject'], $viewer);
        abort_unless($subject !== null, 422, 'Choose a provider, package, booking, client or flight you can see.');
        $hasTime = (bool) ($data['has_time'] ?? false);

        $item = FollowUp::query()->create([
            'user_id' => $viewer->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'type' => ActivityType::from($data['type']),
            'task' => trim($data['task']),
            'due_at' => $hasTime ? CarbonImmutable::parse($data['due_at']) : CarbonImmutable::parse($data['due_at'])->startOfDay(),
            'has_time' => $hasTime,
            'duration_minutes' => $hasTime ? ($data['duration_minutes'] ?? null) : null,
            'contact_name' => $data['contact_name'] ?? null,
            'location' => $data['location'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['data' => [
            'id' => $item->id,
            'subject' => TravelSubjects::key($subject),
            'subject_label' => $item->subjectLabel(),
            'type' => $item->type->value,
            'type_label' => $item->type->label(),
            'task' => $item->task,
            'due_at' => $item->due_at->toIso8601String(),
            'has_time' => $item->has_time,
        ]], 201);
    }
}
