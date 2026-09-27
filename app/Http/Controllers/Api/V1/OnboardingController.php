<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AssignOnboarding;
use App\Enums\OnboardingStatus;
use App\Enums\Permission;
use App\Http\Resources\V1\OnboardingResource;
use App\Models\Onboarding;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * tourlast.com signups: salespeople see their own; people who can see the
 * Partner Register or team performance see all; admins credit unattributed ones.
 */
class OnboardingController extends ApiController
{
    /**
     * GET /onboardings?status=all|awaiting|onboarded|rejected|<status>&user_id=&from=&to=&q=
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $seesAll = $this->seesAll($request);
        abort_unless($seesAll || $this->user($request)->role()?->earnsReferrals(), 403, 'Your account is not allowed to do this.');

        $status = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('q', ''));

        $onboardings = Onboarding::query()
            ->with('user')
            ->when(! $seesAll, fn (Builder $query) => $query->where('user_id', $this->user($request)->id))
            ->when($seesAll && $request->filled('user_id'), fn (Builder $query) => $query->where('user_id', $request->integer('user_id')))
            ->when($status === 'awaiting', fn (Builder $query) => $query->awaitingApproval())
            ->when($status === 'onboarded', fn (Builder $query) => $query->onboarded())
            ->when(OnboardingStatus::tryFrom($status), fn (Builder $query, OnboardingStatus $exact) => $query->where('status', $exact))
            ->when($this->date($request->query('from')), fn (Builder $query, CarbonImmutable $from) => $query->where('submitted_at', '>=', $from->startOfDay()))
            ->when($this->date($request->query('to')), fn (Builder $query, CarbonImmutable $to) => $query->where('submitted_at', '<=', $to->endOfDay()))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('property_name', 'like', "%{$search}%")
                ->orWhere('location', 'like', "%{$search}%")
                ->orWhere('contact_name', 'like', "%{$search}%")))
            ->latest('submitted_at')
            ->paginate($this->perPage($request));

        return OnboardingResource::collection($onboardings);
    }

    /**
     * GET /onboardings/{id}
     */
    public function show(Request $request, int $onboarding): OnboardingResource
    {
        $record = Onboarding::with(['user', 'statusChanges', 'attributionChanges.changedBy'])->findOrFail($onboarding);
        abort_unless($this->seesAll($request) || $record->user_id === $this->user($request)->id, 403, 'Your account is not allowed to do this.');

        return new OnboardingResource($record);
    }

    /**
     * GET /onboardings/unattributed — signups without a referral code (admins).
     */
    public function unattributed(Request $request): AnonymousResourceCollection
    {
        $this->requirePermission($request, Permission::ManageUsers);

        return OnboardingResource::collection(Onboarding::query()->unattributed()->latest('submitted_at')->paginate($this->perPage($request)));
    }

    /**
     * POST /onboardings/{id}/assign — credit an unattributed signup, with a reason on record.
     */
    public function assign(Request $request, int $onboarding, AssignOnboarding $assignOnboarding): OnboardingResource
    {
        $this->requirePermission($request, Permission::ManageUsers);
        $record = Onboarding::query()->unattributed()->findOrFail($onboarding);

        $sellerIds = User::query()->active()->sellers()->pluck('id')->all();
        $data = $request->validate([
            'salesperson_id' => ['required', 'integer', Rule::in($sellerIds)],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], ['reason.min' => 'Give a short reason (at least 10 characters) so the change can be understood later.']);

        $assignOnboarding->handle($record, User::findOrFail($data['salesperson_id']), $this->user($request), $data['reason']);

        return new OnboardingResource($record->fresh(['user', 'statusChanges', 'attributionChanges.changedBy']));
    }

    private function seesAll(Request $request): bool
    {
        $user = $this->user($request);

        return $user->can(Permission::ViewPartnerRegister->value) || $user->can(Permission::ViewTeamPerformance->value);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' && strtotime($value) ? CarbonImmutable::parse($value) : null;
    }
}
