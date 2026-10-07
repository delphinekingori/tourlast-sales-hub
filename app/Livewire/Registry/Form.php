<?php

namespace App\Livewire\Registry;

use App\Actions\SavePropertyEngagement;
use App\Enums\EngagementSource;
use App\Enums\EngagementStage;
use App\Enums\EngagementStatus;
use App\Models\LeadTransfer;
use App\Models\PropertyEngagement;
use App\Models\User;
use App\Support\OutcomeRules;
use App\Support\PropertyDuplicateCheck;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Property Engagement Registry')]
class Form extends Component
{
    public ?PropertyEngagement $engagement = null;

    // Property information
    public string $name = '';

    public string $property_type = 'hotel';

    public string $star_rating = '';

    public string $tourlast_property_id = '';

    public string $website = '';

    public string $trading_name = '';

    public string $registration_name = '';

    public string $registration_number = '';

    public string $kra_pin = '';

    public string $rooms = '';

    public string $capacity = '';

    // Location
    public string $country = '';

    public string $region = '';

    public string $city = '';

    public string $area = '';

    public string $address = '';

    public string $latitude = '';

    public string $longitude = '';

    // Primary contact (new records only; contacts are managed on the profile afterwards)
    public string $contact_name = '';

    public string $contact_title = '';

    public string $contact_phone = '';

    public string $contact_whatsapp = '';

    public string $contact_email = '';

    // Engagement
    public string $sales_rep_id = '';

    public string $first_engaged_on = '';

    public string $stage = '';

    public string $status = '';

    public string $source = '';

    public string $summary = '';

    public string $next_action = '';

    public string $next_action_on = '';

    /** @var array{objection: string, competitor: string, outcome_notes: string, reengage_on: string} Why it was lost / closed. */
    public array $outcome = ['objection' => '', 'competitor' => '', 'outcome_notes' => '', 'reengage_on' => ''];

    /** Required when an existing record's representative is changed here. */
    public string $rep_reason = '';

    public string $rep_notes = '';

    /** The user confirmed the possible duplicates are different properties. */
    public bool $confirmDifferent = false;

    public function mount(?PropertyEngagement $engagement = null): void
    {
        if ($engagement?->exists) {
            Gate::authorize('update', $engagement);
            $this->engagement = $engagement;
            $this->fill(collect($engagement->only([
                'name', 'property_type', 'star_rating', 'tourlast_property_id', 'website', 'trading_name', 'registration_name',
                'registration_number', 'kra_pin', 'rooms', 'capacity', 'country', 'region', 'city', 'area', 'address',
                'latitude', 'longitude', 'summary', 'next_action',
            ]))->map(fn ($value) => (string) $value)->all());
            $this->sales_rep_id = (string) $engagement->sales_rep_id;
            $this->stage = $engagement->stage->value;
            $this->status = $engagement->status->value;
            $this->source = (string) $engagement->source?->value;
            $this->first_engaged_on = $engagement->first_engaged_on->toDateString();
            $this->next_action_on = (string) $engagement->next_action_on?->toDateString();
            $this->outcome = [
                'objection' => (string) $engagement->objection?->value,
                'competitor' => (string) $engagement->competitor,
                'outcome_notes' => (string) $engagement->outcome_notes,
                'reengage_on' => (string) $engagement->reengage_on?->toDateString(),
            ];

            return;
        }

        Gate::authorize('create', PropertyEngagement::class);
        $this->country = config('hub.default_country');
        $this->first_engaged_on = now()->toDateString();
        $this->stage = EngagementStage::Contacted->value;
        $this->status = EngagementStatus::Active->value;
        $this->name = (string) request()->query('name', '');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['name', 'trading_name', 'city', 'contact_phone', 'contact_whatsapp', 'contact_email', 'website', 'registration_number', 'kra_pin'], true)) {
            $this->confirmDifferent = false;
            unset($this->duplicates);
        }
    }

    /**
     * Regions already used in the registry, offered as suggestions.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function knownRegions(): Collection
    {
        return PropertyEngagement::query()->distinct()->orderBy('region')->pluck('region');
    }

    /**
     * Cities already used in the registry, offered as suggestions.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function knownCities(): Collection
    {
        return PropertyEngagement::query()->distinct()->orderBy('city')->pluck('city');
    }

    /**
     * Records that look like the property being entered: registry records,
     * salesperson leads and tourlast.com signups (the Hub-wide duplicate rule).
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function duplicates(): Collection
    {
        return app(PropertyDuplicateCheck::class)->find([
            'name' => $this->name,
            'trading_name' => $this->trading_name,
            'city' => $this->city,
            'phones' => array_merge([$this->contact_phone, $this->contact_whatsapp], $this->engagement?->contacts->pluck('phone')->all() ?? []),
            'emails' => [$this->contact_email],
            'website' => $this->website,
            'registration_number' => $this->registration_number,
            'kra_pin' => $this->kra_pin,
        ], Auth::user(), exceptEngagementId: $this->engagement?->id)
            ->reject(fn (array $match) => $this->engagement && $match['engagementId'] === $this->engagement->id)
            ->values();
    }

    /**
     * Registry records among the matches: these block a new record until confirmed.
     *
     * @return list<int>
     */
    private function registryDuplicateIds(): array
    {
        return $this->duplicates()
            ->where('kind', 'registry')
            ->map(fn (array $match) => (int) str_replace('registry-', '', $match['key']))
            ->values()
            ->all();
    }

    public function save(SavePropertyEngagement $save): void
    {
        $user = Auth::user();
        $this->engagement ? Gate::authorize('update', $this->engagement) : Gate::authorize('create', PropertyEngagement::class);

        $validated = $this->validate($this->rules(), [], $this->attributeLabels());

        $details = [
            'name' => trim($validated['name']),
            'property_type' => $validated['property_type'],
            'star_rating' => $this->isAccommodation() ? ($validated['star_rating'] ?: null) : null,
            'tourlast_property_id' => $validated['tourlast_property_id'] ?: null,
            'website' => $validated['website'] ?: null,
            'trading_name' => $validated['trading_name'] ?: null,
            'registration_name' => $validated['registration_name'] ?: null,
            'registration_number' => $validated['registration_number'] ?: null,
            'kra_pin' => $validated['kra_pin'] ?: null,
            'rooms' => $this->isAccommodation() && $validated['rooms'] !== '' ? (int) $validated['rooms'] : null,
            'capacity' => $validated['capacity'] !== '' ? (int) $validated['capacity'] : null,
            'country' => trim($validated['country']),
            'region' => trim($validated['region']),
            'city' => trim($validated['city']),
            'area' => $validated['area'] ?: null,
            'address' => $validated['address'] ?: null,
            'latitude' => $validated['latitude'] !== '' ? $validated['latitude'] : null,
            'longitude' => $validated['longitude'] !== '' ? $validated['longitude'] : null,
            'sales_rep_id' => (int) $validated['sales_rep_id'],
            'first_engaged_on' => $validated['first_engaged_on'],
            'stage' => EngagementStage::from($validated['stage']),
            'status' => EngagementStatus::from($validated['status']),
            'source' => $validated['source'] ?: null,
            'summary' => $validated['summary'] ?: null,
            'next_action' => $validated['next_action'] ?: null,
            'next_action_on' => $validated['next_action_on'] ?: null,
            'rep_reason' => $validated['rep_reason'] ?: null,
            'rep_notes' => $validated['rep_notes'] ?: null,
            'outcome' => $this->closingStatus() ? OutcomeRules::parse($this->outcome, 'outcome_notes') : null,
        ];

        if ($this->engagement) {
            $engagement = $save->update($this->engagement, $details, $user);
            session()->flash('toast', ['message' => 'Registry record updated.']);
        } else {
            $duplicateIds = $this->registryDuplicateIds();

            if ($duplicateIds !== [] && ! $this->confirmDifferent) {

                $this->addError('confirmDifferent', 'This looks like a property already in the registry. Open the existing record, or confirm it is a different property.');

                return;
            }

            $engagement = $save->create($details, [
                'name' => trim($validated['contact_name']),
                'title' => $validated['contact_title'],
                'phone' => $validated['contact_phone'],
                'whatsapp' => $validated['contact_whatsapp'] ?: null,
                'email' => $validated['contact_email'] ?: null,
            ], $user, $duplicateIds);

            session()->flash('toast', ['message' => 'Property added to the registry.']);
        }

        $this->redirectRoute('registry.show', $engagement, navigate: true);
    }

    /**
     * The chosen status closes or pauses the engagement (Lost, Rejected, Closed, Stalled).
     */
    public function closingStatus(): bool
    {
        return in_array($this->status, ['lost', 'rejected', 'closed', 'stalled'], true);
    }

    /**
     * Lost and Rejected always need the reason on record.
     */
    public function outcomeRequired(): bool
    {
        return in_array($this->status, ['lost', 'rejected'], true);
    }

    /**
     * Editing an existing record and choosing a different representative.
     */
    public function repChanged(): bool
    {
        return $this->engagement !== null && (string) $this->engagement->sales_rep_id !== $this->sales_rep_id;
    }

    public function isAccommodation(): bool
    {
        return in_array($this->property_type, config('hub.accommodation_types'), true);
    }

    public function render(): View
    {
        return view('livewire.registry.form', [
            'salespeople' => User::query()->sellers()->where('is_active', true)
                ->when($this->engagement?->sales_rep_id, fn ($query) => $query->orWhere('id', $this->engagement->sales_rep_id))
                ->orderBy('name')->get(['id', 'name']),
        ])->title($this->engagement ? 'Edit '.$this->engagement->name : 'Add property');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $isNew = $this->engagement === null;

        return [
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
            'rep_reason' => [Rule::requiredIf($this->repChanged()), 'nullable', Rule::in(array_keys(LeadTransfer::Reasons))],
            'rep_notes' => ['nullable', 'string', 'max:1000'],
            ...($this->closingStatus() ? OutcomeRules::rules('outcome.', $this->outcomeRequired(), $this->outcome['objection'] ?: null, 'outcome_notes') : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributeLabels(): array
    {
        return [
            'name' => 'property / business name', 'property_type' => 'property type', 'kra_pin' => 'KRA PIN',
            'region' => 'county / region', 'city' => 'city / town', 'contact_name' => 'contact person name',
            'contact_title' => 'job title', 'contact_phone' => 'phone number', 'contact_whatsapp' => 'WhatsApp number',
            'contact_email' => 'email address', 'sales_rep_id' => 'primary sales representative',
            'first_engaged_on' => 'date first engaged', 'next_action_on' => 'next action date', 'rep_reason' => 'reason for the transfer',
            ...OutcomeRules::attributes('outcome.', 'outcome_notes'),
        ];
    }
}
