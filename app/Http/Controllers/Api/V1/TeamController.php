<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Resources\V1\UserSummaryResource;
use App\Incentives\MonthlyEarnings;
use App\Models\Onboarding;
use App\Models\Target;
use App\Models\User;
use App\Support\Period;
use App\Support\SalesMetrics;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Team Performance and Targets (managers).
 */
class TeamController extends ApiController
{
    /**
     * GET /team/performance?period=month&region= — every active salesperson's
     * points, partners and pace; pay only for people who may see team earnings.
     */
    public function performance(Request $request, SalesMetrics $metrics, MonthlyEarnings $monthlyEarnings): JsonResponse
    {
        $this->requirePermission($request, Permission::ViewTeamPerformance);

        $key = array_key_exists((string) $request->query('period'), Period::options()) ? (string) $request->query('period') : 'month';
        $period = Period::named($key);
        $rows = $metrics->team($period, $request->filled('region') ? (string) $request->query('region') : null);
        $canSeePay = $this->user($request)->can(Permission::ViewTeamEarnings->value) && $period->key === 'month';

        return response()->json([
            'data' => $rows->map(fn (array $row) => [
                'user' => new UserSummaryResource($row['user']),
                'region' => $row['user']->region,
                'metrics' => collect($row['metrics'])->map(fn ($value) => $value instanceof CarbonInterface ? $value->toIso8601String() : $value),
                'status' => $row['status'],
                'progress' => $row['progress'],
                'expected_pay' => $canSeePay && $monthlyEarnings->hasAgreement($row['user'], $period->from)
                    ? $monthlyEarnings->for($row['user'], $period->from)->total()
                    : null,
            ])->values(),
            'meta' => [
                'period' => ['key' => $period->key, 'label' => $period->label(), 'from' => $period->from->toDateString(), 'to' => $period->to->toDateString()],
                'pay_visible' => $canSeePay,
                'totals' => [
                    'onboarded' => $rows->sum(fn ($row) => $row['metrics']['onboarded']),
                    'points' => $rows->sum(fn ($row) => $row['metrics']['points']),
                    'target' => $rows->sum(fn ($row) => $row['metrics']['target'] ?? 0),
                    'awaiting' => $rows->sum(fn ($row) => $row['metrics']['awaiting']),
                    'clicks' => $rows->sum(fn ($row) => $row['metrics']['clicks']),
                    'needs_attention' => $rows->filter(fn ($row) => in_array($row['status']['tone'], ['danger', 'warning'], true))->count(),
                ],
                'regions' => User::query()->active()->sellers()->whereNotNull('region')->distinct()->orderBy('region')->pluck('region'),
            ],
        ]);
    }

    /**
     * GET /team/targets?month=YYYY-MM — the targets salespeople set themselves (read only).
     */
    public function targets(Request $request): JsonResponse
    {
        $this->requirePermission($request, Permission::ViewTeamPerformance);

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $request->query('month'))
            : CarbonImmutable::now()->startOfMonth();

        $targets = Target::query()->whereDate('month', $month->toDateString())->get()->keyBy('user_id');
        $onboarded = Onboarding::query()
            ->creditedBetween($month, $month->endOfMonth())
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $rows = User::query()->active()->sellers()->with('roles')->orderBy('name')->get()->map(fn (User $user) => [
            'user' => new UserSummaryResource($user),
            'target' => isset($targets[$user->id]) ? (int) $targets[$user->id]->target : null,
            'target_set_at' => isset($targets[$user->id]) ? $targets[$user->id]->updated_at?->toIso8601String() : null,
            'onboarded' => (int) ($onboarded[$user->id] ?? 0),
        ]);

        return response()->json([
            'data' => $rows->values(),
            'meta' => [
                'month' => $month->format('Y-m'),
                'locked' => Target::isLockedFor($month),
                'locks_on' => Target::lockDateFor($month)->toDateString(),
                'missing' => $rows->whereNull('target')->count(),
            ],
        ]);
    }
}
