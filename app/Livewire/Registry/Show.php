<?php

namespace App\Livewire\Registry;

use App\Actions\AssignSalesRep;
use App\Actions\LogEngagement;
use App\Actions\SyncOnboardingToRegistry;
use App\Enums\EngagementEventType;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Models\Activity;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadTransfer;
use App\Models\Onboarding;
use App\Models\PropertyEngagement;
use App\Models\User;
use App\Support\DuplicateEngagementFinder;
use App\Support\OutcomeRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class Show extends Component
{
    public PropertyEngagement $record;

    public bool $showLog = false;

    public bool $showReassign = false;

    public bool $showContact = false;

    /** @var array<string, string> */
    public array $log = [];

    public string $newRep = '';

    public string $repReason = '';

    public string $repNotes = '';

    /** @var array<string, string|bool> */
    public array $contact = [];

    public function mount(int|string $engagement): void
    {
        $this->record = PropertyEngagement::withTrashed()->findOrFail($engagement);
        Gate::authorize('view', $this->record);
        $this->resetLog();
    }

    public function openLog(): void
    {
        Gate::authorize('update', $this->record);
        $this->resetLog();
        $this->resetErrorBag();
        $this->showLog = true;
    }

    public function saveLog(LogEngagement $logEngagement): void
    {
        Gate::authorize('update', $this->record);

        $data = $this->validate([
            'log.type' => ['required', Rule::in(array_map(fn (EngagementEventType $type) => $type->value, EngagementEventType::interactions()))],
            'log.rep' => ['required', Rule::exists('users', 'id')],
            'log.happened_on' => ['required', 'date', 'before_or_equal:today'],
            'log.summary' => ['required', 'string', 'max:255'],
            'log.notes' => ['nullable', 'string', 'max:5000'],
            'log.stage' => ['nullable', Rule::enum(EngagementStage::class)],
            'log.status' => ['nullable', Rule::enum(EngagementStatus::class)],
            'log.next_action' => ['nullable', 'string', 'max:255'],
            'log.next_action_on' => ['nullable', 'date'],
            ...OutcomeRules::rules('log.', in_array($this->log['status'] ?? '', ['lost', 'rejected'], true), $this->log['objection'] ?? null, 'outcome_notes'),
        ], [], [
            ...OutcomeRules::attributes('log.', 'outcome_notes'),
            'log.type' => 'engagement type', 'log.rep' => 'salesperson', 'log.happened_on' => 'date', 'log.summary' => 'what happened',
        ])['log'];

        $happenedOn = CarbonImmutable::parse($data['happened_on']);
        $happenedAt = $happenedOn->isToday() ? CarbonImmutable::now() : $happenedOn->setTime(12, 0);

        $logEngagement->handle(
            $this->record,
            Auth::user(),
            EngagementEventType::from($data['type']),
            $happenedAt,
            User::find($data['rep']),
            $data['summary'],
            $data['notes'] ?: null,
            EngagementStage::tryFrom((string) $data['stage']),
            EngagementStatus::tryFrom((string) $data['status']),
            filled($data['next_action']) || filled($data['next_action_on']) ? ($data['next_action'] ?: null) : null,
            filled($data['next_action_on']) ? CarbonImmutable::parse($data['next_action_on']) : null,
            OutcomeRules::parse($data, 'outcome_notes'),
        );

        $this->showLog = false;
        $this->record->refresh();
        $this->dispatch('toast', message: 'Engagement added to the history.');
    }

    public function openReassign(): void
    {
        Gate::authorize('update', $this->record);
        $this->newRep = '';
        $this->repReason = $this->record->sales_rep_id ? '' : 'new_assignment';
        $this->repNotes = '';
        $this->resetErrorBag();
        $this->showReassign = true;
    }

    public function saveReassign(AssignSalesRep $assign): void
    {
        Gate::authorize('update', $this->record);
        $this->validate([
            'newRep' => ['required', Rule::exists('users', 'id'), Rule::notIn([(string) $this->record->sales_rep_id])],
            'repReason' => ['required', Rule::in(array_keys(LeadTransfer::Reasons))],
            'repNotes' => [Rule::requiredIf($this->repReason === 'other'), 'nullable', 'string', 'max:1000'],
        ], ['newRep.not_in' => 'Choose a different salesperson from the current one.'], ['newRep' => 'salesperson', 'repReason' => 'reason', 'repNotes' => 'notes']);

        $transferring = $this->record->sales_rep_id !== null;

        if ($assign->handle($this->record, User::find($this->newRep), Auth::user(), CarbonImmutable::now(), $this->repReason, $this->repNotes ?: null)) {
            $this->dispatch('toast', message: $transferring ? 'Ownership transferred. The previous salesperson stays in the history.' : 'Property assigned.');
        }

        $this->showReassign = false;
        $this->record->refresh();
    }

    public function openContact(): void
    {
        Gate::authorize('update', $this->record);
        $this->contact = ['name' => '', 'title' => '', 'phone' => '', 'whatsapp' => '', 'email' => '', 'is_decision_maker' => false, 'is_primary' => false];
        $this->resetErrorBag();
        $this->showContact = true;
    }

    public function saveContact(): void
    {
        Gate::authorize('update', $this->record);

        $data = $this->validate([
            'contact.name' => ['required', 'string', 'max:255'],
            'contact.title' => ['required', 'string', 'max:100'],
            'contact.phone' => ['nullable', 'required_without:contact.email', 'string', 'max:40', 'regex:/^[+\d][\d\s\-()]{6,}$/'],
            'contact.whatsapp' => ['nullable', 'string', 'max:40', 'regex:/^[+\d][\d\s\-()]{6,}$/'],
            'contact.email' => ['nullable', 'required_without:contact.phone', 'email', 'max:255'],
            'contact.is_decision_maker' => ['boolean'],
            'contact.is_primary' => ['boolean'],
        ], [], ['contact.name' => 'name', 'contact.title' => 'job title', 'contact.phone' => 'phone', 'contact.email' => 'email'])['contact'];

        DB::transaction(function () use ($data): void {
            if ($data['is_primary']) {
                $this->record->contacts()->update(['is_primary' => false]);
            }

            $contact = $this->record->contacts()->create([
                'name' => trim($data['name']),
                'title' => $data['title'],
                'phone' => $data['phone'] ?: null,
                'whatsapp' => $data['whatsapp'] ?: null,
                'email' => $data['email'] ?: null,
                'is_primary' => (bool) $data['is_primary'],
                'is_decision_maker' => (bool) $data['is_decision_maker'],
                'created_by' => Auth::id(),
            ]);

            $this->record->events()->create([
                'type' => EngagementEventType::ContactAdded,
                'sales_rep_id' => $this->record->sales_rep_id,
                'recorded_by' => Auth::id(),
                'summary' => "{$contact->name} · {$contact->title}".($contact->is_decision_maker ? ' · decision maker' : '').($contact->is_primary ? ' · now primary contact' : ''),
                'happened_at' => now(),
            ]);
        });

        $this->showContact = false;
        $this->dispatch('toast', message: 'Contact added.');
    }

    public function removeContact(int $contactId): void
    {
        Gate::authorize('update', $this->record);
        $contact = $this->record->contacts()->whereKey($contactId)->firstOrFail();

        if ($contact->is_primary) {
            $this->dispatch('toast', message: 'Make another contact primary before removing this one.', tone: 'danger');

            return;
        }

        DB::transaction(function () use ($contact): void {
            $this->record->events()->create([
                'type' => EngagementEventType::ContactRemoved,
                'sales_rep_id' => $this->record->sales_rep_id,
                'recorded_by' => Auth::id(),
                'summary' => "{$contact->name} · {$contact->title}",
                'changes' => ['contact' => $contact->only(['name', 'title', 'phone', 'whatsapp', 'email', 'is_decision_maker'])],
                'happened_at' => now(),
            ]);
            $contact->delete();
        });

        $this->dispatch('toast', message: 'Contact removed. Their details are kept in the history.');
    }

    public function makePrimary(int $contactId): void
    {
        Gate::authorize('update', $this->record);
        $contact = $this->record->contacts()->whereKey($contactId)->firstOrFail();

        DB::transaction(function () use ($contact): void {
            $this->record->contacts()->update(['is_primary' => false]);
            $contact->forceFill(['is_primary' => true])->save();
            $this->record->events()->create([
                'type' => EngagementEventType::Edited,
                'sales_rep_id' => $this->record->sales_rep_id,
                'recorded_by' => Auth::id(),
                'summary' => "Primary contact: {$contact->name}",
                'happened_at' => now(),
            ]);
        });
    }

    public function linkLead(int $leadId): void
    {
        Gate::authorize('update', $this->record);
        $lead = Lead::query()->whereNull('property_engagement_id')->findOrFail($leadId);

        DB::transaction(function () use ($lead): void {
            $lead->forceFill(['property_engagement_id' => $this->record->id])->save();
            $this->record->events()->create([
                'type' => EngagementEventType::Linked,
                'sales_rep_id' => $lead->user_id,
                'recorded_by' => Auth::id(),
                'summary' => "Lead linked: {$lead->business_name} ({$lead->user->name})",
                'happened_at' => now(),
            ]);

            if ($lead->onboarding) {
                app(SyncOnboardingToRegistry::class)->handle($lead->onboarding);
            }
        });

        $this->record->refresh();
        $this->dispatch('toast', message: 'Lead linked to this property.');
    }

    public function linkOnboarding(int $onboardingId, SyncOnboardingToRegistry $sync): void
    {
        Gate::authorize('update', $this->record);
        $onboarding = Onboarding::query()->whereNull('property_engagement_id')->findOrFail($onboardingId);
        $onboarding->forceFill(['property_engagement_id' => $this->record->id])->saveQuietly();

        $this->record->events()->create([
            'type' => EngagementEventType::Linked,
            'sales_rep_id' => $onboarding->user_id ?? $this->record->sales_rep_id,
            'recorded_by' => Auth::id(),
            'summary' => "tourlast.com signup linked: {$onboarding->property_name} ({$onboarding->tourlast_property_id})",
            'happened_at' => now(),
        ]);

        $sync->handle($onboarding);
        $this->record->refresh();
        $this->dispatch('toast', message: 'tourlast.com signup linked. The stage now follows tourlast.com.');
    }

    public function archive(): void
    {
        Gate::authorize('delete', $this->record);

        DB::transaction(function (): void {
            $this->record->events()->create([
                'type' => EngagementEventType::Archived,
                'sales_rep_id' => $this->record->sales_rep_id,
                'recorded_by' => Auth::id(),
                'happened_at' => now(),
            ]);
            $this->record->forceFill(['updated_by' => Auth::id()])->save();
            $this->record->delete();
        });

        session()->flash('toast', ['message' => $this->record->name.' archived. Managers can restore it from the archived filter.']);
        $this->redirectRoute('registry.index', navigate: true);
    }

    public function restore(): void
    {
        Gate::authorize('restore', $this->record);

        DB::transaction(function (): void {
            $this->record->restore();
            $this->record->events()->create([
                'type' => EngagementEventType::Restored,
                'sales_rep_id' => $this->record->sales_rep_id,
                'recorded_by' => Auth::id(),
                'happened_at' => now(),
            ]);
        });

        $this->dispatch('toast', message: 'Record restored.');
    }

    public function render(): View
    {
        $this->record->load([
            'contacts', 'salesRep', 'creator:id,name', 'editor:id,name',
            'reps.user:id,name,avatar_path', 'reps.assigner:id,name',
            'events.salesRep:id,name,avatar_path', 'events.recorder:id,name',
            'leads.user:id,name', 'leads.onboarding', 'onboardings.user:id,name',
        ]);
        $canManage = Auth::user()->can('update', $this->record);
        $leadIds = $this->record->leads->pluck('id');

        // Salespeople's calls and meetings on linked leads, read into the property's history (never copied into it).
        $leadActivities = Activity::query()->whereIn('lead_id', $leadIds)->with(['user:id,name', 'lead:id,business_name'])->get();
        $timeline = $this->record->events->map(fn ($event) => ['kind' => 'event', 'at' => $event->happened_at, 'item' => $event])
            ->concat($leadActivities->map(fn ($activity) => ['kind' => 'activity', 'at' => $activity->happened_at, 'item' => $activity]))
            ->sortByDesc(fn (array $entry) => $entry['at']->getTimestamp())
            ->values();

        return view('livewire.registry.show', [
            'timeline' => $timeline,
            'upcoming' => FollowUp::query()->open()->whereIn('lead_id', $leadIds)->with('user:id,name')->chronological()->limit(10)->get(),
            'engagement' => $this->record,
            'canManage' => $canManage,
            'canArchive' => Auth::user()->can('delete', $this->record),
            'salespeople' => $canManage ? User::query()->sellers()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'suggestedLeads' => $canManage ? $this->suggestedLeads() : collect(),
            'suggestedOnboardings' => $canManage ? $this->suggestedOnboardings() : collect(),
            'interactionCount' => $this->record->events->filter(fn ($event) => $event->type->isInteraction())->count() + $leadActivities->count(),
        ])->title($this->record->name);
    }

    private function resetLog(): void
    {
        $this->log = [
            'type' => EngagementEventType::Call->value,
            'rep' => (string) $this->record->sales_rep_id,
            'happened_on' => now()->toDateString(),
            'summary' => '',
            'notes' => '',
            'stage' => '',
            'status' => '',
            'next_action' => (string) $this->record->next_action,
            'next_action_on' => (string) $this->record->next_action_on?->toDateString(),
            'objection' => '',
            'competitor' => '',
            'outcome_notes' => '',
            'reengage_on' => '',
        ];
    }

    /**
     * Unlinked leads that share a phone, email or distinctive name with this property.
     *
     * @return Collection<int, Lead>
     */
    private function suggestedLeads(): Collection
    {
        [$phones, $emails, $tokens] = $this->matchKeys();

        if ($phones === [] && $emails === [] && $tokens === []) {
            return new Collection;
        }

        return Lead::query()
            ->with('user:id,name')
            ->whereNull('property_engagement_id')
            ->where(function (Builder $query) use ($phones, $emails, $tokens): void {
                foreach ($phones as $phone) {
                    $query->orWhere('contact_phone', 'like', '%'.$phone);
                }
                if ($emails !== []) {
                    $query->orWhereIn('contact_email', $emails);
                }
                if ($tokens !== []) {
                    $query->orWhere(function (Builder $name) use ($tokens): void {
                        foreach ($tokens as $token) {
                            $name->where('business_name', 'like', '%'.$token.'%');
                        }
                    });
                }
            })
            ->limit(5)
            ->get();
    }

    /**
     * @return Collection<int, Onboarding>
     */
    private function suggestedOnboardings(): Collection
    {
        [$phones, $emails, $tokens] = $this->matchKeys();

        if ($phones === [] && $emails === [] && $tokens === [] && ! $this->record->tourlast_property_id) {
            return new Collection;
        }

        return Onboarding::query()
            ->with('user:id,name')
            ->whereNull('property_engagement_id')
            ->where(function (Builder $query) use ($phones, $emails, $tokens): void {
                if ($this->record->tourlast_property_id) {
                    $query->orWhere('tourlast_property_id', $this->record->tourlast_property_id);
                }
                foreach ($phones as $phone) {
                    $query->orWhere('contact_phone', 'like', '%'.$phone);
                }
                if ($emails !== []) {
                    $query->orWhereIn('contact_email', $emails);
                }
                if ($tokens !== []) {
                    $query->orWhere(function (Builder $name) use ($tokens): void {
                        foreach ($tokens as $token) {
                            $name->where('property_name', 'like', '%'.$token.'%');
                        }
                    });
                }
            })
            ->limit(5)
            ->get();
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function matchKeys(): array
    {
        $contacts = $this->record->contacts;

        return [
            $contacts->pluck('phone_key')->filter()->unique()->values()->all(),
            $contacts->pluck('email')->filter()->unique()->values()->all(),
            DuplicateEngagementFinder::significantTokens($this->record->name),
        ];
    }
}
