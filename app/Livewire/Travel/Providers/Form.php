<?php

namespace App\Livewire\Travel\Providers;

use App\Actions\Travel\Providers\SaveTravelProvider;
use App\Enums\Permission;
use App\Enums\Travel\TravelProviderStatus;
use App\Enums\Travel\TravelProviderType;
use App\Models\PropertyEngagement;
use App\Models\TravelProvider;
use App\Models\User;
use App\Support\Travel\ProviderDuplicateFinder;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Add or edit a travel provider, checking for an existing provider or
 * Registry record first.
 */
class Form extends Component
{
    public const Fields = [
        'name', 'provider_type', 'business_name', 'trading_name', 'registration_number', 'kra_pin',
        'country', 'region', 'city', 'address', 'website', 'email', 'phone', 'whatsapp',
        'primary_contact_name', 'primary_contact_phone', 'primary_contact_email',
        'decision_maker_name', 'decision_maker_phone', 'description', 'status', 'owner_id',
        'property_engagement_id', 'notes',
    ];

    #[Locked]
    public ?int $providerId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** The user said the possible duplicates are different businesses. */
    public bool $confirmDifferent = false;

    /** Show the duplicate panel (after a save attempt found matches). */
    public bool $checked = false;

    public function mount(int|string|null $provider = null): void
    {
        $user = Auth::user();
        TravelAccess::abortUnlessWorks($user);

        if ($provider !== null) {
            $record = TravelProvider::query()->findOrFail($provider);
            TravelAccess::abortUnlessCanChange($user, $record->owner_id);
            $this->providerId = $record->id;
            $this->form = collect(self::Fields)->mapWithKeys(fn (string $field) => [$field => $record->getAttribute($field) instanceof \BackedEnum ? $record->getAttribute($field)->value : $record->getAttribute($field)])->all();
        } else {
            $this->form = array_fill_keys(self::Fields, null);
            $this->form['provider_type'] = TravelProviderType::TourOperator->value;
            $this->form['status'] = TravelProviderStatus::Prospect->value;
            $this->form['country'] = 'Kenya';
            $this->form['owner_id'] = $user->id;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['form.name', 'form.trading_name', 'form.phone', 'form.email', 'form.website', 'form.registration_number', 'form.kra_pin', 'form.primary_contact_phone', 'form.primary_contact_email', 'form.city'], true)) {
            $this->confirmDifferent = false;
            unset($this->duplicates);
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function duplicates(): Collection
    {
        if (mb_strlen(trim((string) ($this->form['name'] ?? ''))) < 3) {
            return collect();
        }

        return app(ProviderDuplicateFinder::class)->find([
            'name' => $this->form['name'] ?? null,
            'trading_name' => $this->form['trading_name'] ?? null,
            'city' => $this->form['city'] ?? null,
            'phones' => [$this->form['phone'] ?? null, $this->form['primary_contact_phone'] ?? null, $this->form['whatsapp'] ?? null],
            'emails' => [$this->form['email'] ?? null, $this->form['primary_contact_email'] ?? null],
            'website' => $this->form['website'] ?? null,
            'registration_number' => $this->form['registration_number'] ?? null,
            'kra_pin' => $this->form['kra_pin'] ?? null,
        ], Auth::user(), $this->providerId, $this->form['property_engagement_id'] ?? null);
    }

    /**
     * Link the provider to a Property Engagement Registry record.
     */
    public function linkRegistry(int $engagementId): void
    {
        abort_unless(Auth::user()->can(Permission::ViewEngagementRegistry->value), 403);
        abort_unless(PropertyEngagement::query()->whereKey($engagementId)->exists(), 404);
        $this->form['property_engagement_id'] = $engagementId;
        unset($this->duplicates);
    }

    public function unlinkRegistry(): void
    {
        abort_unless(Auth::user()->can(Permission::ViewEngagementRegistry->value), 403);
        $this->form['property_engagement_id'] = null;
        unset($this->duplicates);
    }

    public function continueAnyway(): void
    {
        $user = Auth::user();
        abort_if(ProviderDuplicateFinder::hasStrongMatch($this->duplicates) && ! TravelAccess::managesAll($user), 403);
        $this->confirmDifferent = true;
        $this->save(app(SaveTravelProvider::class));
    }

    public function save(SaveTravelProvider $save): void
    {
        $user = Auth::user();
        $data = $this->validate($this->rules(), [], $this->attributeLabels())['form'];

        $providerMatches = $this->duplicates->where('kind', 'provider');

        if ($providerMatches->isNotEmpty() && ! $this->confirmDifferent) {
            $this->checked = true;

            if (ProviderDuplicateFinder::hasStrongMatch($this->duplicates) && ! TravelAccess::managesAll($user)) {
                $this->addError('form.name', 'This provider is already in the Hub. Open the existing record, or ask a Travel manager if it is a different business.');
            }

            return;
        }

        $record = $this->providerId ? TravelProvider::query()->findOrFail($this->providerId) : null;
        $provider = $save->handle($data, $user, $record);

        session()->flash('toast', ['message' => $record ? 'Provider updated.' : 'Provider added.']);
        $this->redirectRoute('travel.providers.show', $provider->id, navigate: true);
    }

    public function render(): View
    {
        $user = Auth::user();
        $linked = filled($this->form['property_engagement_id'] ?? null)
            ? PropertyEngagement::query()->find($this->form['property_engagement_id'])
            : null;

        return view('livewire.travel.providers.form', [
            'isEdit' => $this->providerId !== null,
            'canManageAll' => TravelAccess::managesAll($user),
            'owners' => TravelAccess::managesAll($user)
                ? User::query()->active()->permission(Permission::AccessTravelSales->value)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'linked' => $linked,
            'seesRegistry' => $user->can(Permission::ViewEngagementRegistry->value),
            'strongMatch' => ProviderDuplicateFinder::hasStrongMatch($this->duplicates),
        ])->title($this->providerId ? 'Edit provider' : 'Add provider');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:255'],
            'form.provider_type' => ['required', Rule::enum(TravelProviderType::class)],
            'form.business_name' => ['nullable', 'string', 'max:255'],
            'form.trading_name' => ['nullable', 'string', 'max:255'],
            'form.registration_number' => ['nullable', 'string', 'max:60'],
            'form.kra_pin' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]\d{9}[A-Za-z]$/'],
            'form.country' => ['required', 'string', 'max:60'],
            'form.region' => ['nullable', 'string', 'max:80'],
            'form.city' => ['nullable', 'string', 'max:80'],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.website' => ['nullable', 'string', 'max:255'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.whatsapp' => ['nullable', 'string', 'max:30'],
            'form.primary_contact_name' => ['nullable', 'string', 'max:255'],
            'form.primary_contact_phone' => ['nullable', 'string', 'max:30'],
            'form.primary_contact_email' => ['nullable', 'email', 'max:255'],
            'form.decision_maker_name' => ['nullable', 'string', 'max:255'],
            'form.decision_maker_phone' => ['nullable', 'string', 'max:30'],
            'form.description' => ['nullable', 'string', 'max:5000'],
            'form.status' => ['required', Rule::enum(TravelProviderStatus::class)],
            'form.owner_id' => ['nullable', Rule::exists('users', 'id')],
            'form.property_engagement_id' => ['nullable', Rule::exists('property_engagements', 'id')],
            'form.notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributeLabels(): array
    {
        return [
            'form.name' => 'provider name',
            'form.provider_type' => 'provider type',
            'form.kra_pin' => 'KRA PIN',
            'form.primary_contact_email' => 'contact email',
            'form.owner_id' => 'salesperson',
        ];
    }
}
