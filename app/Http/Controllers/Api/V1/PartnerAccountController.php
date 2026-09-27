<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Resources\V1\PartnerAccountResource;
use App\Incentives\AccountPoints;
use App\Models\IncentivePolicy;
use App\Models\PartnerAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Partner Accounts: salespeople see their own; verifiers and managers see all.
 * Sales Admins verify Accounts (points become approved) and fail the 14-day review.
 */
class PartnerAccountController extends ApiController
{
    /**
     * GET /partner-accounts?queue=all|verify|review|expansion|failed&user_id=&q=
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $seesAll = $this->seesAll($request);
        abort_unless($seesAll || $this->user($request)->role()?->earnsReferrals(), 403, 'Your account is not allowed to do this.');

        $policy = IncentivePolicy::for(now())->policy();
        $queue = (string) $request->query('queue', 'all');
        $search = trim((string) $request->query('q', ''));

        $accounts = PartnerAccount::query()
            ->current()
            ->with(['user', 'pointEntries', 'checklistItems'])
            ->when(! $seesAll, fn (Builder $query) => $query->where('user_id', $this->user($request)->id))
            ->when($seesAll && $request->filled('user_id'), fn (Builder $query) => $query->where('user_id', $request->integer('user_id')))
            ->when($queue === 'verify', fn (Builder $query) => $query->whereNotNull('activation_date')->where('qualification_status', 'pending')->whereNull('review_failed_at'))
            ->when($queue === 'review', fn (Builder $query) => $query->whereNotNull('activation_date')->whereNull('review_failed_at')->where('activation_date', '>', now()->subDays($policy->reviewDays())))
            ->when($queue === 'expansion', fn (Builder $query) => $query->whereNotNull('activation_date')->whereNull('review_failed_at')->where('activation_date', '>', now()->subDays($policy->expansionDays())))
            ->when($queue === 'failed', fn (Builder $query) => $query->whereNotNull('review_failed_at'))
            ->when($search !== '', fn (Builder $query) => $query->where('legal_name', 'like', "%{$search}%"))
            ->orderByRaw('activation_date is null')
            ->orderByDesc('activation_date')
            ->paginate($this->perPage($request));

        return PartnerAccountResource::collection($accounts);
    }

    /**
     * GET /partner-accounts/{id} — checklist, properties, inventory and points (replaced lines hidden).
     */
    public function show(Request $request, int $account): PartnerAccountResource
    {
        $record = $this->load(PartnerAccount::findOrFail($account));
        Gate::authorize('view-account', $record);

        return new PartnerAccountResource($record);
    }

    /**
     * POST /partner-accounts/{id}/verify — approve the Account's points (Sales Admin).
     */
    public function verify(Request $request, int $account, AccountPoints $accountPoints): PartnerAccountResource
    {
        $this->requirePermission($request, Permission::VerifyAccounts);
        $record = PartnerAccount::findOrFail($account);

        $data = $request->validate([
            'inventory' => ['required', 'integer', 'min:1', 'max:100000'],
            'basis' => ['required', Rule::in(array_keys(PartnerAccount::Bases))],
            'category' => ['required', Rule::in(array_keys(PartnerAccount::Categories))],
            'note' => [in_array($request->input('basis'), ['outlets', 'packages', 'products'], true) ? 'required' : 'nullable', 'string', 'max:250'],
        ], ['note.required' => 'Record why services were not a reasonable measure (paragraph 4.2).']);

        try {
            $record->update(['category' => $data['category']]);
            $accountPoints->verify($record, $this->user($request), (int) $data['inventory'], $data['basis'], $data['note'] ?? null);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['inventory' => collect($exception->errors())->flatten()->all()]);
        }

        return new PartnerAccountResource($this->load($record->fresh()));
    }

    /**
     * POST /partner-accounts/{id}/fail-review — the 14-day review failed; points are cancelled and recovered.
     */
    public function failReview(Request $request, int $account, AccountPoints $accountPoints): PartnerAccountResource
    {
        $this->requirePermission($request, Permission::VerifyAccounts);
        $record = PartnerAccount::findOrFail($account);

        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:250']]);

        try {
            $accountPoints->failReview($record, $this->user($request), $data['reason']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['reason' => collect($exception->errors())->flatten()->all()]);
        }

        return new PartnerAccountResource($this->load($record->fresh()));
    }

    private function load(PartnerAccount $account): PartnerAccount
    {
        return $account->load(['user', 'verifier', 'onboardings', 'checklistItems', 'inventorySnapshots', 'pointEntries']);
    }

    private function seesAll(Request $request): bool
    {
        $user = $this->user($request);

        return $user->can(Permission::VerifyAccounts->value)
            || $user->can(Permission::ViewTeamPerformance->value)
            || $user->can(Permission::ViewTeamEarnings->value);
    }
}
