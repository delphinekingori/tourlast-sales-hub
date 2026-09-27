<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AssignSalesRep;
use App\Actions\LogEngagement;
use App\Actions\SavePropertyEngagement;
use App\Actions\SyncOnboardingToRegistry;
use App\Enums\EngagementEventType;
use App\Enums\EngagementSource;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Http\Resources\V1\DuplicateMatchResource;
use App\Http\Resources\V1\EngagementEventResource;
use App\Http\Resources\V1\EngagementLeadActivityResource;
use App\Http\Resources\V1\PropertyEngagementResource;
use App\Http\Resources\V1\PropertyEngagementSummaryResource;
use App\Models\Activity;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadTransfer;
use App\Models\Onboarding;
use App\Models\PropertyEngagement;
use App\Models\User;
use App\Support\EngagementRegistryFilters;
use App\Support\OutcomeRules;
use App\Support\PropertyDuplicateCheck;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Property Engagement Registry: search, read, and (managers) add, edit, log
 * engagement, assign, link, archive and restore. Mirrors App\Livewire\Registry.
 */
class RegistryController extends ApiController
{
    /**
     * Fields a client may send when creating or editing a record.
     */
    private const DetailFields = [
        'name', 'property_type', 'star_rating', 'tourlast_property_id', 'website', 'trading_name', 'registration_name',
        'registration_number', 'kra_pin', 'rooms', 'capacity', 'country', 'region', 'city', 'area', 'address',
        'latitude', 'longitude', 'sales_rep_id', 'first_engaged_on', 'stage', 'status', 'source', 'summary',
        'next_action', 'next_action_on',
    ];

    /**
     * GET /registry
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PropertyEngagement::class);

        $input = $request->query();
        $input['archived'] = $request->boolean('archived') && $this->user($request)->can('create', PropertyEngagement::class);
        $filters = EngagementRegistryFilters::fromArray($input);

        return PropertyEngagementSummaryResource::collection($filters->query()->paginate($this->perPage($request))->withQueryString())
            ->additional(['summary' => $this->summary()]);
    }

    /**
     * GET /registry/{engagement}
     */
    public function show(int $engagement): PropertyEngagementResource
    {
        $record = PropertyEngagement::withTrashed()->findOrFail($engagement);
        Gate::authorize('view', $record);

        return $this->profile($record);
    }

    /**
     * POST /registry
     */
    public function store(Request $request, SavePropertyEngagement $save, PropertyDuplicateCheck $check): JsonResponse
    {
        Gate::authorize('create', PropertyEngagement::class);
        $input = $request->all();
        $validated = $this->validateDetails($input, null);

        $matches = $check->find([
            'name' => $validated['name'],
            'trading_name' => $validated['trading_name'] ?? null,
            'city' => $validated['city'],
            'phones' => [$validated['contact_phone'] ?? null, $validated['contact_whatsapp'] ?? null],
            'emails' => [$validated['contact_email'] ?? null],
            'website' => $validated['website'] ?? null,
            'registration_number' => $validated['registration_number'] ?? null,
            'kra_pin' => $validated['kra_pin'] ?? null,
        ], $this->user($request));

        $duplicateIds = $matches->where('kind', 'registry')->map(fn (array $match) => (int) str_replace('registry-', '', $match['key']))->values()->all();

        if ($duplicateIds !== [] && ! $request->boolean('confirm_different')) {
            return response()->json([
                'message' => 'This looks like a property already in the registry. Open the existing record, or send confirm_different=true if it is a different property.',
                'matches' => DuplicateMatchResource::collection($matches),
            ], 409);
        }

        $record = $save->create($this->details($validated), [
            'name' => trim($validated['contact_name']),
            'title' => $validated['contact_title'],
            'phone' => $validated['contact_phone'],
            'whatsapp' => $validated['contact_whatsapp'] ?? null,
            'email' => $validated['contact_email'] ?? null,
        ], $this->user($request), $duplicateIds);

        return $this->profile($record)->response()->setStatusCode(201);
    }

    /**
     * PATCH /registry/{engagement} — send only the fields that change.
     */
    public function update(Request $request, int $engagement, SavePropertyEngagement $save): PropertyEngagementResource
    {
        $record = PropertyEngagement::withTrashed()->findOrFail($engagement);
        Gate::authorize('update', $record);

        $input = array_merge($this->current($record), $request->only([...self::DetailFields, 'rep_reason', 'rep_notes', 'objection', 'competitor', 'outcome_notes', 'reengage_on']));
        $validated = $this->validateDetails($input, $record);

        $record = $save->update($record, $this->details($validated), $this->user($request));

        return $this->profile($record);
    }

    /**
     * POST /registry/{engagement}/engagements — add a call, meeting, proposal… to the history.
     */
    public function logEngagement(Request $request, int $engagement, LogEngagement $logEngagement): PropertyEngagementResource
    {
        $record = PropertyEngagement::withTrashed()->findOrFail($engagement);
        Gate::authorize('update', $record);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_map(fn (EngagementEventType $type) => $type->value, EngagementEventType::interactions()))],
            'rep_id' => [Rule::requiredIf($record->sales_rep_id === null), 'nullable', Rule::exists('users', 'id')],
            'happened_on' => ['required', 'date', 'before_or_equal:today'],
            'summary' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'stage' => ['nullable', Rule::enum(EngagementStage::class)],
            'status' => ['nullable', Rule::enum(EngagementStatus::class)],
            'next_action' => ['nullable', 'string', 'max:255'],
            'next_action_on' => ['nullable', 'date'],
            ...OutcomeRules::rules('', in_array($request->input('status'), ['lost', 'rejected'], true), $request->input('objection'), 'outcome_notes'),
        ], [], [...OutcomeRules::attributes('', 'outcome_notes'), 'rep_id' => 'salesperson', 'happened_on' => 'date', 'summary' => 'what happened']);

        $happenedOn = CarbonImmutable::parse($data['happened_on']);

        $logEngagement->handle(
            $record,
            $this->user($request),
            EngagementEventType::from($data['type']),
            $happenedOn->isToday() ? CarbonImmutable::now() : $happenedOn->setTime(12, 0),
            User::find($data['rep_id'] ?? $record->sales_rep_id),
            $data['summary'],
            ($data['notes'] ?? null) ?: null,
            EngagementStage::tryFrom((string) ($data['stage'] ?? '')),
            EngagementStatus::tryFrom((string) ($data['status'] ?? '')),
            filled($data['next_action'] ?? null) || filled($data['next_action_on'] ?? null) ? (($data['next_action'] ?? null) ?: null) : null,
            filled($data['next_action_on'] ?? null) ? CarbonImmutable::parse($data['next_action_on']) : null,
            OutcomeRules::parse($data, 'outcome_notes'),
        );

        return $this->profile($record->refresh());
    }

    /**
     * POST /registry/{engagement}/assign — assign (no rep yet) or transfer ownership.
     */
    public function assign(Request $request, int $engagement, AssignSalesRep $assign): PropertyEngagementResource
    {
        $record = PropertyEngagement::withTrashed()->findOrFail($engagement);
        Gate::authorize('update', $record);

        $input = $request->all();
        $input['reason'] ??= $record->sales_rep_id ? null : 'new_assignment';

        $data = validator($input, [
            'rep_id' => ['required', Rule::exists('users', 'id'), Rule::notIn([(string) $record->sales_rep_id, $record->sales_rep_id])],
            'reason' => ['required', Rule::in(array_keys(LeadTransfer::Reasons))],
            'notes' => [Rule::requiredIf(($input['reason'] ?? null) === 'other'), 'nullable', 'string', 'max:1000'],
        ], ['rep_id.not_in' => 'Choose a different salesperson from the current one.'], ['rep_id' => 'salesperson'])->validate();

        $assign->handle($record, User::find($data['rep_id']), $this->user($request), CarbonImmutable::now(), $data['reason'], ($data['notes'] ?? null) ?: null);

        return $this->profile($record->refresh());
    }

    /**
     * POST /registry/{engagement}/links — link a salesperson lead or a tourlast.com signup.
     */
    public function link(Request $request, int $engagement, SyncOnboardingToRegistry $sync): PropertyEngagementResource
    {
        $record = PropertyEngagement::withTrashed()->findOrFail($engagement);
        Gate::authorize('update', $record);

        $data = $request->validate([
            'lead_id' => ['required_without:onboarding_id', 'prohibits:onboarding_id', 'nullable', 'integer', Rule::exists('leads', 'id')],
            'onboarding_id' => ['required_without:lead_id', 'nullable', 'integer', Rule::exists('onboardings', 'id')],
        ]);
        $user = $this->user($request);

        if (filled($data['lead_id'] ?? null)) {
            $lead = Lead::query()->findOrFail($data['lead_id']);

            if ($lead->property_engagement_id !== null) {
                throw ValidationException::withMessages(['lead_id' => 'This lead is already linked to a registry record.']);
            }

            DB::transaction(function () use ($lead, $record, $user, $sync): void {
                $lead->forceFill(['property_engagement_id' => $record->id])->save();
                $record->events()->create([
                    'type' => EngagementEventType::Linked,
                    'sales_rep_id' => $lead->user_id,
                    'recorded_by' => $user->id,
                    'summary' => "Lead linked: {$lead->business_name} ({$lead->user->name})",
                    'happened_at' => now(),
                ]);

                if ($lead->onboarding) {
                    $sync->handle($lead->onboarding);
                }
            });
        } else {
            $onboarding = Onboarding::query()->findOrFail($data['onboarding_id']);

            if ($onboarding->property_engagement_id !== null) {
                throw ValidationException::withMessages(['onboarding_id' => 'This signup is already linked to a registry record.']);
            }

            $onboarding->forceFill(['property_engagement_id' => $record->id])->saveQuietly();
            $record->events()->create([
                'type' => EngagementEventType::Linked,
                'sales_rep_id' => $onboarding->user_id ?? $record->sales_rep_id,
                'recorded_by' => $user->id,
                'summary' => "tourlast.com signup linked: {$onboarding->property_name} ({$onboarding->tourlast_property_id})",
                'happened_at' => now(),
            ]);
            $sync->handle($onboarding);
        }

        return $this->profile($record->refresh());
    }

    /**
     * POST /registry/{engagement}/archive — hide the record; its history is kept.
     */
    public function archive(Request $request, int $engagement): PropertyEngagementResource
    {
        $record = PropertyEngagement::withTrashed()->findOrFail($engagement);
        Gate::authorize('delete', $record);
        abort_if($record->trashed(), 422, 'This record is already archived.');
        $user = $this->user($request);

        DB::transaction(function () use ($record, $user): void {
            $record->events()->create([
                'type' => EngagementEventType::Archived,
                'sales_rep_id' => $record->sales_rep_id,
                'recorded_by' => $user->id,
                'happened_at' => now(),
            ]);
            $record->forceFill(['updated_by' => $user->id])->save();
            $record->delete();
        });

        return $this->profile($record);
    }

    /**
     * POST /registry/{engagement}/restore
     */
    public function restore(Request $request, int $engagement): PropertyEngagementResource
    {
        $record = PropertyEngagement::withTrashed()->findOrFail($engagement);
        Gate::authorize('restore', $record);
        abort_unless($record->trashed(), 422, 'This record is not archived.');
        $user = $this->user($request);

        DB::transaction(function () use ($record, $user): void {
            $record->restore();
            $record->events()->create([
                'type' => EngagementEventType::Restored,
                'sales_rep_id' => $record->sales_rep_id,
                'recorded_by' => $user->id,
                'happened_at' => now(),
            ]);
        });

        return $this->profile($record);
    }

    /**
     * The full profile, with the timeline merged exactly as the web profile shows it:
     * registry events plus calls and meetings on linked leads, newest first.
     */
    private function profile(PropertyEngagement $record): PropertyEngagementResource
    {
        $record->load([
            'contacts', 'salesRep', 'creator:id,name', 'editor:id,name',
            'reps.user:id,name,avatar_path', 'reps.assigner:id,name',
            'events.salesRep:id,name', 'events.recorder:id,name',
            'leads.user:id,name', 'onboardings.user:id,name',
        ]);
        $leadIds = $record->leads->pluck('id');
        $activities = Activity::query()->whereIn('lead_id', $leadIds)->with(['user:id,name', 'lead:id,business_name'])->get();

        $timeline = $record->events->map(fn ($event) => ['at' => $event->happened_at, 'item' => new EngagementEventResource($event)])
            ->concat($activities->map(fn ($activity) => ['at' => $activity->happened_at, 'item' => new EngagementLeadActivityResource($activity)]))
            ->sortByDesc(fn (array $entry) => $entry['at']->getTimestamp())
            ->pluck('item')
            ->values();

        $upcoming = FollowUp::query()->open()->whereIn('lead_id', $leadIds)->with(['user:id,name,avatar_path', 'lead:id,business_name,property_engagement_id'])->chronological()->limit(10)->get();

        return (new PropertyEngagementResource($record))->withProfile($timeline, $upcoming);
    }

    /**
     * Validate a full set of record fields (the same rules as the web form).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateDetails(array $input, ?PropertyEngagement $record): array
    {
        $isNew = $record === null;
        $status = (string) ($input['status'] ?? '');
        $closing = in_array($status, ['lost', 'rejected', 'closed', 'stalled'], true);
        $repChanged = ! $isNew && (string) $record->sales_rep_id !== (string) ($input['sales_rep_id'] ?? '');

        return validator($input, [
            'name' => ['required', 'string', 'max:255'],
            'property_type' => ['required', Rule::in(array_keys(config('hub.property_types')))],
            'star_rating' => ['nullable', Rule::in(array_keys(config('hub.star_ratings')))],
            'tourlast_property_id' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'registration_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'kra_pin' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z]\d{9}[A-Za-z]$/'],
            'rooms' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'country' => ['required', 'string', 'max:100'],
            'region' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'contact_name' => [Rule::requiredIf($isNew), 'nullable', 'string', 'max:255'],
            'contact_title' => [Rule::requiredIf($isNew), 'nullable', 'string', 'max:100'],
            'contact_phone' => [Rule::requiredIf($isNew), 'nullable', 'string', 'max:40', 'regex:/^[+\d][\d\s\-()]{6,}$/'],
            'contact_whatsapp' => ['nullable', 'string', 'max:40', 'regex:/^[+\d][\d\s\-()]{6,}$/'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'sales_rep_id' => ['required', Rule::exists('users', 'id')],
            'first_engaged_on' => ['required', 'date', 'before_or_equal:today'],
            'stage' => ['required', Rule::enum(EngagementStage::class)],
            'status' => ['required', Rule::enum(EngagementStatus::class)],
            'source' => ['nullable', Rule::enum(EngagementSource::class)],
            'summary' => ['nullable', 'string', 'max:5000'],
            'next_action' => ['nullable', 'string', 'max:255'],
            'next_action_on' => ['nullable', 'date'],
            'rep_reason' => [Rule::requiredIf($repChanged), 'nullable', Rule::in(array_keys(LeadTransfer::Reasons))],
            'rep_notes' => ['nullable', 'string', 'max:1000'],
            ...($closing ? OutcomeRules::rules('', in_array($status, ['lost', 'rejected'], true), ($input['objection'] ?? null) ?: null, 'outcome_notes') : []),
        ], [], [
            'name' => 'property / business name', 'property_type' => 'property type', 'kra_pin' => 'KRA PIN',
            'region' => 'county / region', 'city' => 'city / town', 'contact_name' => 'contact person name',
            'contact_title' => 'job title', 'contact_phone' => 'phone number', 'sales_rep_id' => 'primary sales representative',
            'first_engaged_on' => 'date first engaged', 'rep_reason' => 'reason for the transfer',
            ...OutcomeRules::attributes('', 'outcome_notes'),
        ])->validate() + ['_closing' => $closing];
    }

    /**
     * Turn validated input into the attributes SavePropertyEngagement expects.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function details(array $validated): array
    {
        $value = fn (string $key) => filled($validated[$key] ?? null) ? $validated[$key] : null;
        $accommodation = in_array($validated['property_type'], config('hub.accommodation_types'), true);

        return [
            'name' => trim($validated['name']),
            'property_type' => $validated['property_type'],
            'star_rating' => $accommodation ? $value('star_rating') : null,
            'tourlast_property_id' => $value('tourlast_property_id'),
            'website' => $value('website'),
            'trading_name' => $value('trading_name'),
            'registration_name' => $value('registration_name'),
            'registration_number' => $value('registration_number'),
            'kra_pin' => $value('kra_pin'),
            'rooms' => $accommodation && filled($validated['rooms'] ?? null) ? (int) $validated['rooms'] : null,
            'capacity' => filled($validated['capacity'] ?? null) ? (int) $validated['capacity'] : null,
            'country' => trim($validated['country']),
            'region' => trim($validated['region']),
            'city' => trim($validated['city']),
            'area' => $value('area'),
            'address' => $value('address'),
            'latitude' => $value('latitude'),
            'longitude' => $value('longitude'),
            'sales_rep_id' => (int) $validated['sales_rep_id'],
            'first_engaged_on' => $validated['first_engaged_on'],
            'stage' => EngagementStage::from($validated['stage']),
            'status' => EngagementStatus::from($validated['status']),
            'source' => $value('source'),
            'summary' => $value('summary'),
            'next_action' => $value('next_action'),
            'next_action_on' => $value('next_action_on'),
            'rep_reason' => $value('rep_reason'),
            'rep_notes' => $value('rep_notes'),
            'outcome' => $validated['_closing'] ? OutcomeRules::parse($validated, 'outcome_notes') : null,
        ];
    }

    /**
     * The record's current values, so PATCH can send only what changes.
     *
     * @return array<string, mixed>
     */
    private function current(PropertyEngagement $record): array
    {
        return [
            ...collect($record->only(['name', 'property_type', 'star_rating', 'tourlast_property_id', 'website', 'trading_name', 'registration_name',
                'registration_number', 'kra_pin', 'rooms', 'capacity', 'country', 'region', 'city', 'area', 'address',
                'latitude', 'longitude', 'summary', 'next_action', 'competitor', 'outcome_notes']))->all(),
            'sales_rep_id' => $record->sales_rep_id,
            'stage' => $record->stage->value,
            'status' => $record->status->value,
            'source' => $record->source?->value,
            'first_engaged_on' => $record->first_engaged_on->toDateString(),
            'next_action_on' => $record->next_action_on?->toDateString(),
            'objection' => $record->objection?->value,
            'reengage_on' => $record->reengage_on?->isFuture() ? $record->reengage_on->toDateString() : null,
        ];
    }

    /**
     * Registry-wide counts (not affected by filters), as the registry page shows.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $byStatus = PropertyEngagement::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'engaged' => (int) $byStatus->only(EngagementRegistryFilters::openStatuses())->sum(),
            'onboarding' => PropertyEngagement::query()->whereIn('stage', EngagementStage::onboarding())->whereIn('status', EngagementRegistryFilters::openStatuses())->count(),
            'stalled' => (int) ($byStatus[EngagementStatus::Stalled->value] ?? 0),
            'lost' => (int) (($byStatus[EngagementStatus::Lost->value] ?? 0) + ($byStatus[EngagementStatus::Rejected->value] ?? 0)),
            'live' => PropertyEngagement::query()->where('stage', EngagementStage::Live)->count(),
        ];
    }
}
