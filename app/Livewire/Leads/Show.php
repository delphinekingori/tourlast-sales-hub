<?php

namespace App\Livewire\Leads;

use App\Actions\CreateRegistryRecord;
use App\Actions\LogActivity;
use App\Actions\MarkLeadLost;
use App\Actions\TransferLead;
use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Models\Lead;
use App\Models\LeadTransfer;
use App\Models\ReferralClick;
use App\Models\User;
use App\Support\DuplicateEngagementFinder;
use App\Support\OutcomeRules;
use App\Support\PropertyDuplicateCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public int $leadId;

    public bool $showEdit = false;

    public bool $showActivity = false;

    /** @var array<string, string> */
    public array $form = [];

    /** @var array{type: string, happened_at: string, notes: string, next_action: string, follow_up_at: string, follow_up_time: string} */
    public array $activity = ['type' => 'call', 'happened_at' => '', 'notes' => '', 'next_action' => '', 'follow_up_at' => '', 'follow_up_time' => ''];

    public bool $showLost = false;

    /** @var array{objection: string, competitor: string, notes: string, reengage_on: string} */
    public array $lost = ['objection' => '', 'competitor' => '', 'notes' => '', 'reengage_on' => ''];

    public bool $showTransfer = false;

    /** @var array{to: string, reason: string, notes: string, with_registry: bool} */
    public array $transfer = ['to' => '', 'reason' => '', 'notes' => '', 'with_registry' => true];

    /** @var list<array{id: int, name: string, location: string, owner: ?string}> */
    public array $registryMatches = [];

    public function mount(Lead $lead): void
    {
        abort_unless($lead->user_id === Auth::id() || Auth::user()->can(Permission::ViewTeamPerformance->value), 403);
        $this->leadId = $lead->id;
    }

    public function openEdit(): void
    {
        $lead = $this->ownedLead();
        $this->resetValidation();
        $this->form = collect($lead->only(['business_name', 'trading_name', 'property_type', 'location', 'contact_name', 'contact_role', 'contact_phone', 'contact_email', 'website', 'registration_number', 'kra_pin', 'notes']))
            ->map(fn ($value) => (string) $value)->all();
        $this->showEdit = true;
    }

    public function saveEdit(): void
    {
        $lead = $this->ownedLead();

        $validated = $this->validate([
            'form.business_name' => ['required', 'string', 'max:190'],
            'form.property_type' => ['required', Rule::in(array_keys(config('hub.property_types')))],
            'form.location' => ['nullable', 'string', 'max:190'],
            'form.contact_name' => ['nullable', 'string', 'max:190'],
            'form.contact_role' => ['nullable', 'string', 'max:120'],
            'form.contact_phone' => ['nullable', 'string', 'max:40'],
            'form.contact_email' => ['nullable', 'email', 'max:190'],
            'form.trading_name' => ['nullable', 'string', 'max:190'],
            'form.website' => ['nullable', 'string', 'max:190'],
            'form.registration_number' => ['nullable', 'string', 'max:100'],
            'form.kra_pin' => ['nullable', 'string', 'max:30'],
            'form.notes' => ['nullable', 'string', 'max:5000'],
        ], [], ['form.business_name' => 'business name', 'form.kra_pin' => 'KRA PIN'])['form'];

        $lead->update([
            ...array_map(fn ($value) => $value === '' ? null : $value, $validated),
            'contact_email' => filled($validated['contact_email'] ?? null) ? strtolower($validated['contact_email']) : null,
        ]);

        $this->showEdit = false;
        $this->dispatch('toast', message: 'Lead updated.');
    }

    /**
     * Other records that look like this lead's property (the Hub-wide duplicate rule).
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function duplicates(): Collection
    {
        if (! $this->showEdit) {
            return collect();
        }

        $lead = Lead::findOrFail($this->leadId);

        return app(PropertyDuplicateCheck::class)->find([
            'name' => $this->form['business_name'] ?? '',
            'trading_name' => $this->form['trading_name'] ?? '',
            'city' => $this->form['location'] ?? '',
            'phones' => [$this->form['contact_phone'] ?? null],
            'emails' => [$this->form['contact_email'] ?? null],
            'website' => $this->form['website'] ?? null,
            'registration_number' => $this->form['registration_number'] ?? null,
            'kra_pin' => $this->form['kra_pin'] ?? null,
        ], Auth::user(), exceptLeadId: $lead->id, exceptEngagementId: $lead->property_engagement_id)
            ->reject(fn (array $match) => $lead->property_engagement_id && $match['engagementId'] === $lead->property_engagement_id)
            ->values();
    }

    /**
     * Put the lead in the Property Engagement Registry. When records that look like the
     * same property exist, they are shown first and the owner confirms it is a different one.
     */
    public function addToRegistry(bool $confirmed = false): void
    {
        $lead = $this->ownedLead();

        if ($lead->property_engagement_id) {
            return;
        }

        if (! $confirmed) {
            $matches = app(DuplicateEngagementFinder::class)->find([
                'name' => $lead->business_name,
                'trading_name' => $lead->trading_name,
                'city' => $lead->location,
                'phones' => [$lead->contact_phone],
                'emails' => [$lead->contact_email],
                'website' => $lead->website,
                'registration_number' => $lead->registration_number,
                'kra_pin' => $lead->kra_pin,
            ]);

            if ($matches->isNotEmpty()) {
                $this->registryMatches = $matches->map(fn (array $match): array => [
                    'id' => $match['engagement']->id,
                    'name' => $match['engagement']->name,
                    'location' => $match['engagement']->locationLabel(),
                    'owner' => $match['engagement']->salesRep?->name,
                ])->all();

                return;
            }
        }

        $this->registryMatches = [];
        app(CreateRegistryRecord::class)->fromLead($lead, Auth::user());
        $this->dispatch('toast', message: 'Added to the Property Engagement Registry.');
    }

    public function setStatus(string $status): void
    {
        $lead = $this->ownedLead();
        $status = LeadStatus::from($status);

        abort_unless(in_array($status, LeadStatus::manual(), true), 422);
        abort_if($lead->status === LeadStatus::Onboarded, 422);

        // Lost always goes through markLost(), which requires the reason.
        abort_if($status === LeadStatus::Lost, 422);

        // Re-opening a lost lead: the scheduled re-engagement has happened.
        if ($lead->status === LeadStatus::Lost) {
            $lead->followUps()->whereNull('completed_at')->where('task', 'Re-engage after loss')->delete();
        }

        $lead->update([
            'status' => $status,
            'lost_reason' => null,
            'reengage_on' => null,
        ]);

        $this->dispatch('toast', message: "Status changed to {$status->label()}.");
    }

    public function openLost(): void
    {
        $lead = $this->ownedLead();
        abort_if($lead->status === LeadStatus::Onboarded, 422);
        $this->resetValidation();
        $this->lost = ['objection' => '', 'competitor' => '', 'notes' => '', 'reengage_on' => ''];
        $this->showLost = true;
    }

    public function markLost(MarkLeadLost $markLeadLost): void
    {
        $lead = $this->ownedLead();
        abort_if($lead->status === LeadStatus::Onboarded, 422);

        $data = $this->validate(
            OutcomeRules::rules('lost.', true, $this->lost['objection'] ?? null),
            [],
            OutcomeRules::attributes('lost.'),
        )['lost'];

        $outcome = OutcomeRules::parse($data);
        $markLeadLost->handle($lead, Auth::user(), $outcome['objection'], $outcome['competitor'], $outcome['notes'], $outcome['reengage_on']);

        $this->showLost = false;
        $this->dispatch('toast', message: $outcome['reengage_on'] ? 'Marked lost. Re-engagement scheduled for '.$outcome['reengage_on']->format('j M Y').'.' : 'Marked lost.');
    }

    public function openTransfer(): void
    {
        abort_unless(Auth::user()->can(Permission::TransferOwnership->value), 403);
        $this->resetValidation();
        $this->transfer = ['to' => '', 'reason' => '', 'notes' => '', 'with_registry' => true];
        $this->showTransfer = true;
    }

    public function saveTransfer(TransferLead $transferLead): void
    {
        abort_unless(Auth::user()->can(Permission::TransferOwnership->value), 403);
        $lead = Lead::findOrFail($this->leadId);

        $data = $this->validate([
            'transfer.to' => ['required', Rule::exists('users', 'id')->where('is_active', true), Rule::notIn([(string) $lead->user_id])],
            'transfer.reason' => ['required', Rule::in(array_keys(LeadTransfer::Reasons))],
            'transfer.notes' => [Rule::requiredIf(($this->transfer['reason'] ?? '') === 'other'), 'nullable', 'string', 'max:1000'],
            'transfer.with_registry' => ['boolean'],
        ], ['transfer.to.not_in' => 'Choose a different salesperson from the current owner.'], [
            'transfer.to' => 'new salesperson', 'transfer.reason' => 'reason', 'transfer.notes' => 'notes',
        ])['transfer'];

        $to = User::findOrFail($data['to']);
        abort_unless($to->role()?->earnsReferrals(), 422);

        $transferLead->handle($lead, $to, Auth::user(), $data['reason'], $data['notes'] ?: null, (bool) $data['with_registry']);

        $this->showTransfer = false;
        $this->dispatch('toast', message: "Transferred to {$to->name}.");
    }

    public function openActivity(): void
    {
        $this->ownedLead();
        $this->resetValidation();
        $this->activity = [
            'type' => ActivityType::Call->value,
            'happened_at' => now()->format('Y-m-d\TH:i'),
            'notes' => '', 'next_action' => '', 'follow_up_at' => '', 'follow_up_time' => '',
        ];
        $this->showActivity = true;
    }

    public function saveActivity(LogActivity $logActivity): void
    {
        $lead = $this->ownedLead();

        $this->validate([
            'activity.type' => ['required', Rule::enum(ActivityType::class)],
            'activity.happened_at' => ['required', 'date', 'before_or_equal:'.now()->addHour()->toDateTimeString()],
            'activity.notes' => ['nullable', 'string', 'max:5000'],
            'activity.next_action' => ['nullable', 'string', 'max:190'],
            'activity.follow_up_at' => ['nullable', 'date', 'after_or_equal:today'],
            'activity.follow_up_time' => ['nullable', 'date_format:H:i'],
        ], [], ['activity.happened_at' => 'date', 'activity.follow_up_at' => 'follow-up date']);

        $logActivity->handle(
            $lead,
            Auth::user(),
            ActivityType::from($this->activity['type']),
            CarbonImmutable::parse($this->activity['happened_at']),
            $this->activity['notes'] ?: null,
            $this->activity['next_action'] ?: null,
            $this->activity['follow_up_at'] ? CarbonImmutable::parse($this->activity['follow_up_at'].(filled($this->activity['follow_up_time'] ?? '') ? ' '.$this->activity['follow_up_time'] : '')) : null,
            followUpHasTime: filled($this->activity['follow_up_at']) && filled($this->activity['follow_up_time'] ?? ''),
        );

        $this->showActivity = false;
        $this->dispatch('toast', message: 'Activity logged.');
    }

    #[On('schedule-saved')]
    public function refreshSchedule(): void
    {
        // Re-render with the updated schedule.
    }

    public function render(): View
    {
        $lead = Lead::with(['user.referralCode', 'onboarding', 'activities.user', 'followUps', 'transfers.fromUser:id,name', 'transfers.toUser:id,name', 'transfers.transferrer:id,name'])->findOrFail($this->leadId);

        return view('livewire.leads.show', [
            'lead' => $lead,
            'isOwner' => $lead->user_id === Auth::id(),
            'canTransfer' => Auth::user()->can(Permission::TransferOwnership->value),
            'salespeople' => Auth::user()->can(Permission::TransferOwnership->value) ? User::query()->active()->sellers()->whereKeyNot($lead->user_id)->orderBy('name')->get(['id', 'name']) : collect(),
            'openFollowUps' => $lead->followUps->whereNull('completed_at')->sortBy(fn ($item) => [$item->due_at->toDateString(), ! $item->has_time, $item->due_at])->values(),
            'manualStatuses' => LeadStatus::manual(),
            'activityTypes' => ActivityType::cases(),
            'clicks' => $lead->user->referralCode ? ReferralClick::query()->where('lead_id', $lead->id)->count() : 0,
        ])->title($lead->business_name);
    }

    private function ownedLead(): Lead
    {
        $lead = Lead::findOrFail($this->leadId);
        abort_unless($lead->user_id === Auth::id(), 403);

        return $lead;
    }
}
