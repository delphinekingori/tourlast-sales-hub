<?php

namespace App\Livewire\Leads;

use App\Actions\TransferLead;
use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Models\Lead;
use App\Models\LeadTransfer;
use App\Models\PropertyEngagement;
use App\Models\User;
use App\Support\Alerts;
use App\Support\PropertyDuplicateCheck;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Leads')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'open';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $owner = '';

    public bool $showCreate = false;

    /** @var array<string, string> */
    public array $form = [];

    public bool $showBulkTransfer = false;

    /** @var array{to: string, reason: string, notes: string} */
    public array $bulk = ['to' => '', 'reason' => '', 'notes' => ''];

    /** The salesperson confirmed the possible duplicates are different properties. */
    public bool $confirmDifferent = false;

    public function mount(): void
    {
        abort_unless($this->canSell() || $this->canSeeAll(), 403);
        $this->resetForm();
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['status', 'search', 'owner'], true)) {
            $this->resetPage();
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['form.business_name', 'form.trading_name', 'form.location', 'form.contact_phone', 'form.contact_email', 'form.website', 'form.registration_number', 'form.kra_pin'], true)) {
            $this->confirmDifferent = false;
            unset($this->duplicates);
        }
    }

    public function openCreate(): void
    {
        abort_unless($this->canSell(), 403);
        $this->resetValidation();
        $this->resetForm();
        $this->confirmDifferent = false;
        $this->showCreate = true;
    }

    public function create(): void
    {
        abort_unless($this->canSell(), 403);

        $validated = $this->validate([
            'form.business_name' => ['required', 'string', 'max:190'],
            'form.trading_name' => ['nullable', 'string', 'max:190'],
            'form.property_type' => ['required', Rule::in(array_keys(config('hub.property_types')))],
            'form.location' => ['nullable', 'string', 'max:190'],
            'form.contact_name' => ['nullable', 'string', 'max:190'],
            'form.contact_role' => ['nullable', 'string', 'max:120'],
            'form.contact_phone' => ['nullable', 'string', 'max:40'],
            'form.contact_email' => ['nullable', 'email', 'max:190'],
            'form.website' => ['nullable', 'string', 'max:190'],
            'form.registration_number' => ['nullable', 'string', 'max:100'],
            'form.kra_pin' => ['nullable', 'string', 'max:30'],
            'form.notes' => ['nullable', 'string', 'max:5000'],
            'form.property_engagement_id' => ['nullable', Rule::exists('property_engagements', 'id')->whereNull('deleted_at')],
        ], [], ['form.business_name' => 'business name', 'form.contact_email' => 'email', 'form.kra_pin' => 'KRA PIN'])['form'];

        $duplicates = $this->duplicates();
        $linked = filled($validated['property_engagement_id'] ?? null);

        // The global rule: a lead that looks like an existing property is either
        // attached to that engagement or explicitly confirmed as a different one.
        if ($duplicates->isNotEmpty() && ! $linked && ! $this->confirmDifferent) {
            $this->addError('confirmDifferent', 'This looks like a property Tourlast already knows. Continue the existing engagement, or confirm it is a different property.');

            return;
        }

        $lead = Lead::create([
            ...array_map(fn ($value) => $value === '' ? null : $value, $validated),
            'contact_email' => filled($validated['contact_email'] ?? null) ? strtolower($validated['contact_email']) : null,
            'user_id' => Auth::id(),
            'status' => LeadStatus::New,
        ]);

        $this->alertOverlaps($lead, $duplicates, $linked);

        $this->showCreate = false;
        $this->redirectRoute('leads.show', $lead, navigate: true);
    }

    /**
     * "Continue existing engagement": open your own lead, or start your lead
     * attached to the existing registry record.
     */
    public function continueWith(string $kind, int $id): void
    {
        abort_unless($this->canSell(), 403);

        if ($kind === 'lead') {
            $lead = Lead::query()->where('user_id', Auth::id())->findOrFail($id);
            $this->showCreate = false;
            $this->redirectRoute('leads.show', $lead, navigate: true);

            return;
        }

        $engagement = PropertyEngagement::query()->findOrFail($id);
        Gate::authorize('view', $engagement);

        $existing = Lead::query()->where('user_id', Auth::id())->where('property_engagement_id', $engagement->id)->first();

        if ($existing) {
            $this->showCreate = false;
            $this->redirectRoute('leads.show', $existing, navigate: true);

            return;
        }

        $this->form['property_engagement_id'] = (string) $engagement->id;
        $this->form['business_name'] = $this->form['business_name'] ?: $engagement->name;
        $this->create();
    }

    /**
     * Possible existing properties for the lead being added, across the
     * registry, every salesperson's leads and tourlast.com signups.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function duplicates(): Collection
    {
        if (! $this->showCreate) {
            return collect();
        }

        return app(PropertyDuplicateCheck::class)->find([
            'name' => $this->form['business_name'] ?? '',
            'trading_name' => $this->form['trading_name'] ?? '',
            'city' => $this->form['location'] ?? '',
            'phones' => [$this->form['contact_phone'] ?? null],
            'emails' => [$this->form['contact_email'] ?? null],
            'website' => $this->form['website'] ?? null,
            'registration_number' => $this->form['registration_number'] ?? null,
            'kra_pin' => $this->form['kra_pin'] ?? null,
        ], Auth::user());
    }

    /**
     * Hand every open lead of the filtered salesperson to someone else
     * (for example when they leave or change territory).
     */
    public function openBulkTransfer(): void
    {
        abort_unless(Auth::user()->can(Permission::TransferOwnership->value) && $this->owner !== '', 403);
        $this->resetValidation();
        $this->bulk = ['to' => '', 'reason' => '', 'notes' => ''];
        $this->showBulkTransfer = true;
    }

    public function saveBulkTransfer(TransferLead $transferLead): void
    {
        abort_unless(Auth::user()->can(Permission::TransferOwnership->value) && $this->owner !== '', 403);
        $from = User::findOrFail((int) $this->owner);

        $data = $this->validate([
            'bulk.to' => ['required', Rule::exists('users', 'id')->where('is_active', true), Rule::notIn([(string) $from->id])],
            'bulk.reason' => ['required', Rule::in(array_keys(LeadTransfer::Reasons))],
            'bulk.notes' => [Rule::requiredIf(($this->bulk['reason'] ?? '') === 'other'), 'nullable', 'string', 'max:1000'],
        ], [], ['bulk.to' => 'new salesperson', 'bulk.reason' => 'reason'])['bulk'];

        $to = User::findOrFail($data['to']);
        abort_unless($to->role()?->earnsReferrals(), 422);

        $leads = Lead::query()->where('user_id', $from->id)->open()->with(['user', 'propertyEngagement'])->get();

        DB::transaction(function () use ($leads, $to, $data, $transferLead): void {
            foreach ($leads as $lead) {
                $transferLead->handle($lead, $to, Auth::user(), $data['reason'], $data['notes'] ?: null, notify: false);
            }
        });

        if ($leads->isNotEmpty()) {
            Alerts::send('ownership_transferred', 'Leads transferred to you', "{$leads->count()} open leads moved from {$from->name} to {$to->name} by ".Auth::user()->name.'. Reason: '.LeadTransfer::reasonLabelFor($data['reason']).'.', route('leads.index'), $to);
        }

        $this->showBulkTransfer = false;
        $this->owner = (string) $to->id;
        $this->dispatch('toast', message: "{$leads->count()} open ".Str::plural('lead', $leads->count())." transferred to {$to->name}.");
    }

    public function render(): View
    {
        return view('livewire.leads.index', [
            'leads' => $this->leads(),
            'statuses' => LeadStatus::cases(),
            'canSell' => $this->canSell(),
            'canSeeAll' => $this->canSeeAll(),
            'owners' => $this->canSeeAll() ? User::query()->sellers()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active']) : collect(),
            'canTransfer' => Auth::user()->can(Permission::TransferOwnership->value),
            'ownerOpenCount' => $this->owner !== '' ? Lead::query()->where('user_id', (int) $this->owner)->open()->count() : 0,
            'transferTargets' => Auth::user()->can(Permission::TransferOwnership->value) ? User::query()->active()->sellers()->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    private function leads(): LengthAwarePaginator
    {
        return Lead::query()
            ->with(['nextFollowUp', 'user'])
            ->when(! $this->canSeeAll(), fn ($query) => $query->where('user_id', Auth::id()))
            ->when($this->canSeeAll() && $this->owner !== '', fn ($query) => $query->where('user_id', $this->owner))
            ->when($this->status === 'open', fn ($query) => $query->open())
            ->when($this->status !== 'open' && $this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('business_name', 'like', "%{$this->search}%")
                ->orWhere('contact_name', 'like', "%{$this->search}%")
                ->orWhere('location', 'like', "%{$this->search}%")))
            ->orderByRaw('last_contacted_at is null desc')
            ->latest('updated_at')
            ->paginate(20);
    }

    /**
     * Tell management (and the salesperson already on it) when a lead overlaps
     * someone else's active engagement, whether joined knowingly or overridden.
     *
     * @param  Collection<int, array<string, mixed>>  $duplicates
     */
    private function alertOverlaps(Lead $lead, Collection $duplicates, bool $linked): void
    {
        $others = $duplicates->filter(fn (array $match) => $match['active'] && $match['owner'] && ! $match['ownerIsViewer']);

        if ($others->isEmpty()) {
            return;
        }

        $who = Auth::user()->name;
        $list = $others->map(fn (array $match) => "{$match['name']} ({$match['owner']}, {$match['stage']})")->implode('; ');

        if ($linked) {
            Alerts::send('duplicate_property', 'Shared property', "{$who} started a lead on {$lead->business_name}, continuing the existing engagement: {$list}.", route('leads.show', $lead));
        } else {
            Alerts::send('duplicate_property', 'Possible duplicate lead', "{$who} added {$lead->business_name} and confirmed it is a different property from: {$list}. Please check.", route('leads.show', $lead));
        }
    }

    private function resetForm(): void
    {
        $this->form = [
            'business_name' => '', 'property_type' => 'hotel', 'location' => '', 'contact_name' => '',
            'contact_role' => '', 'contact_phone' => '', 'contact_email' => '', 'notes' => '', 'property_engagement_id' => '',
            'trading_name' => '', 'website' => '', 'registration_number' => '', 'kra_pin' => '',
        ];
    }

    private function canSell(): bool
    {
        return (bool) Auth::user()->role()?->earnsReferrals();
    }

    private function canSeeAll(): bool
    {
        return Auth::user()->can(Permission::ViewTeamPerformance->value);
    }
}
