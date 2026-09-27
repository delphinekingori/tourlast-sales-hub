<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MarkLeadLost;
use App\Actions\TransferLead;
use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Http\Resources\V1\DuplicateMatchResource;
use App\Http\Resources\V1\LeadResource;
use App\Models\Lead;
use App\Models\LeadTransfer;
use App\Models\PropertyEngagement;
use App\Models\User;
use App\Support\Alerts;
use App\Support\OutcomeRules;
use App\Support\PropertyDuplicateCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Leads: a salesperson's active opportunities. Mirrors Leads\Index and Leads\Show.
 */
class LeadController extends ApiController
{
    /**
     * GET /leads — own leads; managers with team performance see everyone's.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $seesAll = $user->can(Permission::ViewTeamPerformance->value);
        abort_unless($seesAll || $user->role()?->earnsReferrals(), 403, 'Your account is not allowed to do this.');

        $status = (string) $request->query('status', 'open');
        $search = trim((string) $request->query('q', ''));
        $owner = (string) $request->query('owner', '');

        $leads = Lead::query()
            ->with(['nextFollowUp', 'user'])
            ->when(! $seesAll, fn ($query) => $query->where('user_id', $user->id))
            ->when($seesAll && $owner !== '', fn ($query) => $query->where('user_id', (int) $owner))
            ->when($status === 'open', fn ($query) => $query->open())
            ->when($status !== 'open' && $status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('business_name', 'like', "%{$search}%")
                ->orWhere('contact_name', 'like', "%{$search}%")
                ->orWhere('location', 'like', "%{$search}%")))
            ->orderByRaw('last_contacted_at is null desc')
            ->latest('updated_at')
            ->paginate($this->perPage($request));

        return LeadResource::collection($leads);
    }

    /**
     * POST /leads — sellers only. Applies the Hub-wide duplicate rule: a lead
     * that looks like a known property must either continue that engagement
     * (property_engagement_id) or be confirmed different (confirm_different).
     */
    public function store(Request $request, PropertyDuplicateCheck $duplicateCheck): JsonResponse
    {
        $this->requireSeller($request);
        $user = $this->user($request);

        $validated = $request->validate($this->fieldRules() + [
            'property_engagement_id' => ['nullable', Rule::exists('property_engagements', 'id')->whereNull('deleted_at')],
            'confirm_different' => ['sometimes', 'boolean'],
        ], [], ['business_name' => 'business name', 'contact_email' => 'email', 'kra_pin' => 'KRA PIN']);

        $linked = filled($validated['property_engagement_id'] ?? null);

        if ($linked) {
            $engagement = PropertyEngagement::query()->findOrFail($validated['property_engagement_id']);
            Gate::authorize('view', $engagement);
        }

        $duplicates = $duplicateCheck->find([
            'name' => $validated['business_name'],
            'trading_name' => $validated['trading_name'] ?? '',
            'city' => $validated['location'] ?? '',
            'phones' => [$validated['contact_phone'] ?? null],
            'emails' => [$validated['contact_email'] ?? null],
            'website' => $validated['website'] ?? null,
            'registration_number' => $validated['registration_number'] ?? null,
            'kra_pin' => $validated['kra_pin'] ?? null,
        ], $user);

        if ($duplicates->isNotEmpty() && ! $linked && ! $request->boolean('confirm_different')) {
            return response()->json([
                'message' => 'This looks like a property Tourlast already knows. Continue the existing engagement (property_engagement_id), or confirm it is a different property (confirm_different: true).',
                'matches' => DuplicateMatchResource::collection($duplicates),
            ], 409);
        }

        unset($validated['confirm_different']);

        $lead = Lead::create([
            ...array_map(fn ($value) => $value === '' ? null : $value, $validated),
            'contact_email' => filled($validated['contact_email'] ?? null) ? strtolower($validated['contact_email']) : null,
            'user_id' => $user->id,
            'status' => LeadStatus::New,
        ]);

        $this->alertOverlaps($user, $lead, $duplicates, $linked);

        return (new LeadResource($lead->load(['user', 'propertyEngagement'])))->response()->setStatusCode(201);
    }

    /**
     * GET /leads/{id} — the owner or managers with team performance.
     */
    public function show(Request $request, Lead $lead): LeadResource
    {
        $this->viewableLead($request, $lead);

        return new LeadResource($lead->load([
            'user', 'propertyEngagement', 'onboarding', 'activities.user', 'followUps',
            'transfers.fromUser:id,name,avatar_path', 'transfers.toUser:id,name,avatar_path', 'transfers.transferrer:id,name,avatar_path',
        ]));
    }

    /**
     * PATCH /leads/{id} — owner only.
     */
    public function update(Request $request, Lead $lead): LeadResource
    {
        $this->ownedLead($request, $lead);

        $rules = collect($this->fieldRules())->map(fn (array $rules) => ['sometimes', ...$rules])->all();
        $validated = $request->validate($rules, [], ['business_name' => 'business name', 'kra_pin' => 'KRA PIN']);

        if (array_key_exists('contact_email', $validated)) {
            $validated['contact_email'] = filled($validated['contact_email']) ? strtolower($validated['contact_email']) : null;
        }

        $lead->update(array_map(fn ($value) => $value === '' ? null : $value, $validated));

        return new LeadResource($lead->fresh(['user', 'propertyEngagement']));
    }

    /**
     * POST /leads/{id}/status — set a manual status (not Lost: use /lost).
     */
    public function status(Request $request, Lead $lead): LeadResource
    {
        $this->ownedLead($request, $lead);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn (LeadStatus $status) => $status->value, LeadStatus::manual()))],
        ]);
        $status = LeadStatus::from($data['status']);

        abort_if($lead->status === LeadStatus::Onboarded, 422, 'Onboarded leads are set by tourlast.com and cannot be changed.');
        abort_if($status === LeadStatus::Lost, 422, 'Use POST /leads/{id}/lost to mark a lead lost with its reason.');

        // Re-opening a lost lead: the scheduled re-engagement has happened.
        if ($lead->status === LeadStatus::Lost) {
            $lead->followUps()->whereNull('completed_at')->where('task', 'Re-engage after loss')->delete();
        }

        $lead->update([
            'status' => $status,
            'lost_reason' => null,
            'reengage_on' => null,
        ]);

        return new LeadResource($lead->fresh(['user']));
    }

    /**
     * POST /leads/{id}/lost — owner; the primary objection is required.
     */
    public function lost(Request $request, Lead $lead, MarkLeadLost $markLeadLost): LeadResource
    {
        $this->ownedLead($request, $lead);
        abort_if($lead->status === LeadStatus::Onboarded, 422, 'Onboarded leads cannot be marked lost.');

        $data = $request->validate(
            OutcomeRules::rules('', true, $request->input('objection')),
            [],
            OutcomeRules::attributes(''),
        );

        $outcome = OutcomeRules::parse($data);
        $markLeadLost->handle($lead, $this->user($request), $outcome['objection'], $outcome['competitor'], $outcome['notes'], $outcome['reengage_on']);

        return new LeadResource($lead->fresh(['user', 'followUps']));
    }

    /**
     * POST /leads/{id}/transfer — managers with the transfer permission.
     */
    public function transfer(Request $request, Lead $lead, TransferLead $transferLead): LeadResource
    {
        $this->requirePermission($request, Permission::TransferOwnership);

        $data = $request->validate([
            'to_user_id' => ['required', Rule::exists('users', 'id')->where('is_active', true), Rule::notIn([(string) $lead->user_id])],
            'reason' => ['required', Rule::in(array_keys(LeadTransfer::Reasons))],
            'notes' => [Rule::requiredIf($request->input('reason') === 'other'), 'nullable', 'string', 'max:1000'],
            'with_registry' => ['sometimes', 'boolean'],
        ], ['to_user_id.not_in' => 'Choose a different salesperson from the current owner.'], ['to_user_id' => 'new salesperson']);

        $to = User::findOrFail($data['to_user_id']);
        abort_unless($to->role()?->earnsReferrals(), 422, 'Leads can only be transferred to people who sell.');

        $transferLead->handle($lead, $to, $this->user($request), $data['reason'], ($data['notes'] ?? null) ?: null, $request->boolean('with_registry', true));

        return new LeadResource($lead->fresh(['user', 'transfers.fromUser:id,name', 'transfers.toUser:id,name', 'transfers.transferrer:id,name']));
    }

    /**
     * POST /leads/transfer-bulk — hand every open lead of one salesperson to another.
     */
    public function transferBulk(Request $request, TransferLead $transferLead): JsonResponse
    {
        $this->requirePermission($request, Permission::TransferOwnership);

        $data = $request->validate([
            'from_user_id' => ['required', Rule::exists('users', 'id')],
            'to_user_id' => ['required', Rule::exists('users', 'id')->where('is_active', true), 'different:from_user_id'],
            'reason' => ['required', Rule::in(array_keys(LeadTransfer::Reasons))],
            'notes' => [Rule::requiredIf($request->input('reason') === 'other'), 'nullable', 'string', 'max:1000'],
        ], [], ['from_user_id' => 'current salesperson', 'to_user_id' => 'new salesperson']);

        $from = User::findOrFail($data['from_user_id']);
        $to = User::findOrFail($data['to_user_id']);
        abort_unless($to->role()?->earnsReferrals(), 422, 'Leads can only be transferred to people who sell.');

        $by = $this->user($request);
        $leads = Lead::query()->where('user_id', $from->id)->open()->with(['user', 'propertyEngagement'])->get();

        DB::transaction(function () use ($leads, $to, $data, $by, $transferLead): void {
            foreach ($leads as $lead) {
                $transferLead->handle($lead, $to, $by, $data['reason'], ($data['notes'] ?? null) ?: null, notify: false);
            }
        });

        if ($leads->isNotEmpty()) {
            Alerts::send('ownership_transferred', 'Leads transferred to you', "{$leads->count()} open leads moved from {$from->name} to {$to->name} by {$by->name}. Reason: ".LeadTransfer::reasonLabelFor($data['reason']).'.', route('leads.index'), $to);
        }

        return response()->json([
            'message' => "{$leads->count()} open leads transferred to {$to->name}.",
            'transferred' => $leads->count(),
            'lead_ids' => $leads->pluck('id')->values(),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function fieldRules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:190'],
            'trading_name' => ['nullable', 'string', 'max:190'],
            'property_type' => ['required', Rule::in(array_keys(config('hub.property_types')))],
            'location' => ['nullable', 'string', 'max:190'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'contact_role' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'website' => ['nullable', 'string', 'max:190'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'kra_pin' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Tell management (and the salesperson already on it) when a lead overlaps
     * someone else's active engagement, whether joined knowingly or overridden.
     *
     * @param  Collection<int, array<string, mixed>>  $duplicates
     */
    private function alertOverlaps(User $user, Lead $lead, Collection $duplicates, bool $linked): void
    {
        $others = $duplicates->filter(fn (array $match) => $match['active'] && $match['owner'] && ! $match['ownerIsViewer']);

        if ($others->isEmpty()) {
            return;
        }

        $who = $user->name;
        $list = $others->map(fn (array $match) => "{$match['name']} ({$match['owner']}, {$match['stage']})")->implode('; ');

        if ($linked) {
            Alerts::send('duplicate_property', 'Shared property', "{$who} started a lead on {$lead->business_name}, continuing the existing engagement: {$list}.", route('leads.show', $lead));
        } else {
            Alerts::send('duplicate_property', 'Possible duplicate lead', "{$who} added {$lead->business_name} and confirmed it is a different property from: {$list}. Please check.", route('leads.show', $lead));
        }
    }

    private function viewableLead(Request $request, Lead $lead): void
    {
        $user = $this->user($request);
        abort_unless($lead->user_id === $user->id || $user->can(Permission::ViewTeamPerformance->value), 403, 'This lead belongs to someone else.');
    }

    private function ownedLead(Request $request, Lead $lead): void
    {
        abort_unless($lead->user_id === $this->user($request)->id, 403, 'Only the lead\'s owner can change it.');
    }
}
