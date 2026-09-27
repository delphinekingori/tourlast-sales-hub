<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\IssueReferralCode;
use App\Actions\SavePaymentDetail;
use App\Actions\SetTarget;
use App\Enums\LeadStatus;
use App\Http\Resources\V1\MeResource;
use App\Http\Resources\V1\ScheduleItemResource;
use App\Models\Lead;
use App\Models\Onboarding;
use App\Models\PaymentDetail;
use App\Models\Target;
use App\Support\EarningsSummary;
use App\Support\Period;
use App\Support\SalesMetrics;
use App\Support\TodayOverview;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The token owner: profile, dashboard, referral link, target, payment details, earnings.
 */
class MeController extends ApiController
{
    /**
     * GET /me
     */
    public function show(Request $request): MeResource
    {
        return new MeResource($this->user($request)->load('referralCode'));
    }

    /**
     * PATCH /me — update your own profile details.
     */
    public function update(Request $request): MeResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['sometimes', 'required', 'string', 'max:32'],
            'job_title' => ['sometimes', 'required', 'string', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $this->user($request)->update(array_map(fn ($value) => $value === '' ? null : $value, $data));

        return new MeResource($this->user($request)->fresh('referralCode'));
    }

    /**
     * GET /me/dashboard?period=month — today, schedule, pipeline and performance.
     */
    public function dashboard(Request $request, SalesMetrics $metrics): JsonResponse
    {
        $this->requireSeller($request);
        $user = $this->user($request);
        $period = Period::named(array_key_exists((string) $request->query('period'), Period::options()) ? (string) $request->query('period') : 'month');
        $pipeline = Lead::query()->where('user_id', $user->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json(['data' => [
            'period' => ['key' => $period->key, 'label' => $period->label(), 'from' => $period->from->toDateString(), 'to' => $period->to->toDateString()],
            'today' => TodayOverview::counts($user),
            'schedule' => ScheduleItemResource::collection(TodayOverview::schedule($user)),
            'pipeline' => collect(LeadStatus::cases())->mapWithKeys(fn (LeadStatus $status) => [$status->value => (int) ($pipeline[$status->value] ?? 0)]),
            'metrics' => $metrics->forUser($user, $period),
            'approved_in_period' => Onboarding::query()->where('user_id', $user->id)->whereBetween('approved_at', [$period->from, $period->to])->count(),
            'trend' => collect($metrics->monthlyTrend($user))->map(fn (array $month) => [
                'month' => $month['month']->format('Y-m'), 'points' => $month['points'], 'partners' => $month['count'], 'target' => $month['target'],
            ]),
        ]]);
    }

    /**
     * GET /me/referral?period=month — your link and its funnel.
     */
    public function referral(Request $request, IssueReferralCode $issueReferralCode, SalesMetrics $metrics): JsonResponse
    {
        $this->requireSeller($request);
        $user = $this->user($request);
        $code = $issueReferralCode->handle($user);
        $period = Period::named(array_key_exists((string) $request->query('period'), Period::options()) ? (string) $request->query('period') : 'month');
        $m = $metrics->forUser($user, $period);

        return response()->json(['data' => [
            'code' => $code?->code,
            'link' => $code?->shareUrl(),
            'period' => $period->key,
            'funnel' => [
                'link_visits' => $m['clicks'],
                'applications' => $m['submitted'],
                'approved' => Onboarding::query()->where('user_id', $user->id)->whereBetween('approved_at', [$period->from, $period->to])->count(),
                'live' => $m['onboarded'],
                'active' => $m['active'],
            ],
            'conversion_percent' => $m['conversion'],
        ]]);
    }

    /**
     * GET /me/target — your targets and which months can still change.
     */
    public function target(Request $request): JsonResponse
    {
        $this->requireSeller($request);
        $user = $this->user($request);
        $months = [CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->startOfMonth()->addMonth()];

        return response()->json(['data' => collect($months)->map(fn (CarbonImmutable $month) => [
            'month' => $month->format('Y-m'),
            'target' => Target::query()->where('user_id', $user->id)->whereDate('month', $month->toDateString())->value('target'),
            'locked' => Target::isLockedFor($month),
            'locks_on' => Target::lockDateFor($month)->toDateString(),
        ])]);
    }

    /**
     * PUT /me/target — set your points target for this month (until the lock day) or next month.
     */
    public function updateTarget(Request $request, SetTarget $setTarget): JsonResponse
    {
        $this->requireSeller($request);
        $settable = array_map(fn (string $date) => CarbonImmutable::parse($date)->format('Y-m'), array_keys(Target::settableMonths()));

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m', Rule::in($settable)],
            'target' => ['required', 'integer', 'min:1', 'max:500'],
        ], ['month.in' => 'That month is locked. You can set this month until the lock day, and next month.']);

        $target = $setTarget->handle($this->user($request), CarbonImmutable::createFromFormat('!Y-m', $data['month']), (int) $data['target']);

        return response()->json(['data' => ['month' => $data['month'], 'target' => $target->target]]);
    }

    /**
     * GET /me/payment-details — how you are paid (masked).
     */
    public function paymentDetails(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->paymentDetailData($this->user($request)->paymentDetail)]);
    }

    /**
     * PUT /me/payment-details — set M-Pesa or bank details. HR and Finance are alerted.
     */
    public function updatePaymentDetails(Request $request, SavePaymentDetail $savePaymentDetail): JsonResponse
    {
        $mpesa = $request->input('method') === 'mpesa';

        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(PaymentDetail::Methods))],
            'mpesa_phone' => [Rule::requiredIf($mpesa), 'nullable', 'string', 'regex:/^(\+?254|0)?\s?(7|1)\d{2}\s?\d{3}\s?\d{3}$/'],
            'mpesa_name' => [Rule::requiredIf($mpesa), 'nullable', 'string', 'min:3', 'max:120'],
            'bank_name' => [Rule::requiredIf(! $mpesa), 'nullable', 'string', 'max:120'],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'account_number' => [Rule::requiredIf(! $mpesa), 'nullable', 'string', 'regex:/^[0-9A-Za-z\s-]{6,34}$/'],
            'account_name' => [Rule::requiredIf(! $mpesa), 'nullable', 'string', 'min:3', 'max:120'],
        ], ['mpesa_phone.regex' => 'Enter a Kenyan mobile number such as 0712 345 678.']);

        $detail = $savePaymentDetail->handle($this->user($request), $data);

        return response()->json(['data' => $this->paymentDetailData($detail)]);
    }

    /**
     * Payment details for the owner's own screen: enough to recognise, not to copy.
     *
     * @return array<string, mixed>|null
     */
    private function paymentDetailData(?PaymentDetail $detail): ?array
    {
        return $detail ? [
            'method' => $detail->method,
            'method_label' => PaymentDetail::Methods[$detail->method] ?? $detail->method,
            'payee_name' => $detail->payeeName(),
            'destination' => $detail->maskedDestination(),
            'bank_name' => $detail->bank_name,
            'bank_branch' => $detail->bank_branch,
            'complete' => $detail->isComplete(),
            'updated_at' => $detail->updated_at?->toIso8601String(),
        ] : null;
    }

    /**
     * GET /me/earnings?month=YYYY-MM — your pay for a month.
     */
    public function earnings(Request $request, EarningsSummary $summary): JsonResponse
    {
        $user = $this->user($request);
        Gate::authorize('view-earnings', $user);
        $month = $request->filled('month') && preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $request->query('month'))
            : CarbonImmutable::now()->startOfMonth();

        return response()->json(['data' => $summary->for($user, $month)]);
    }
}
