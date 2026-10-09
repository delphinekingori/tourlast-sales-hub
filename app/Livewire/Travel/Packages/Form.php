<?php

namespace App\Livewire\Travel\Packages;

use App\Actions\Travel\Packages\CreatePackage;
use App\Actions\Travel\Packages\SavePackage;
use App\Actions\Travel\Packages\SetPackageTeam;
use App\Actions\Travel\Packages\SubmitPackage;
use App\Actions\Travel\Packages\SyncPackageMedia;
use App\Enums\Travel\MediaCategory;
use App\Enums\Travel\ResourceStatus;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\MediaAsset;
use App\Models\Package;
use App\Models\PackageItineraryDay;
use App\Models\PackageVersion;
use App\Models\ProviderContract;
use App\Models\TravelProvider;
use App\Models\User;
use App\Support\Travel\PackageContent;
use App\Support\Travel\PackageDuplicateFinder;
use App\Support\Travel\PackageReadiness;
use App\Support\Travel\TravelAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Create or edit a package in tabs. Saving an approved package never changes
 * the approved version: SavePackage creates the next version instead.
 */
class Form extends Component
{
    #[Locked]
    public ?int $packageId = null;

    #[Url]
    public string $tab = 'basics';

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<array<string, mixed>> */
    public array $itinerary = [];

    /** @var list<int> */
    public array $media = [];

    public ?int $primaryMedia = null;

    /** @var array{driver_id: string, guide_id: string, guide_required: bool} */
    public array $team = ['driver_id' => '', 'guide_id' => '', 'guide_required' => false];

    public bool $acceptDuplicate = false;

    public bool $showMediaPicker = false;

    public string $mediaSearch = '';

    public string $mediaProvider = '';

    public string $mediaDestination = '';

    public string $mediaCategory = '';

    public const Tabs = [
        'basics' => 'Basics',
        'content' => 'Content',
        'itinerary' => 'Itinerary',
        'pricing' => 'Pricing',
        'media' => 'Media',
        'team' => 'Driver & guide',
    ];

    public function mount(?Package $package = null): void
    {
        $user = Auth::user();
        TravelAccess::abortUnlessWorks($user);

        if (! array_key_exists($this->tab, self::Tabs)) {
            $this->tab = 'basics';
        }

        if (! $package?->exists) {
            $this->form = $this->blankForm();
            $this->itinerary = [$this->blankDay()];

            if ($providerId = (int) request()->query('provider')) {
                $this->form['travel_provider_id'] = (string) $providerId;
            }

            return;
        }

        TravelAccess::abortUnlessCanChange($user, $package->owner_id);
        abort_if($package->archived_at !== null, 404);

        $version = $package->workingVersion ?? $package->liveVersion;

        if ($version?->isAwaitingReview()) {
            session()->flash('toast', ['message' => 'This version is awaiting approval. Withdraw it from review to edit.', 'tone' => 'danger']);
            $this->redirectRoute('travel.packages.show', $package, navigate: true);

            return;
        }

        $this->packageId = $package->id;
        $content = PackageContent::contentOf($version);

        foreach (PackageContent::ListFields as $field) {
            $content[$field] = array_values($content[$field] ?? []) ?: [''];
        }

        $this->form = array_map(fn ($value) => is_array($value) ? $value : ($value === null ? '' : (string) $value), $content);
        $this->form['nights'] = (string) ($content['nights'] ?? 0);
        $this->itinerary = array_map(fn (array $day) => [...$day, 'meals' => $day['meals'] ?? [], 'description' => $day['description'] ?? '', 'activities' => $day['activities'] ?? '', 'accommodation' => $day['accommodation'] ?? '', 'transport' => $day['transport'] ?? '', 'notes' => $day['notes'] ?? ''], PackageContent::itineraryOf($version)) ?: [$this->blankDay()];
        $this->media = $package->media()->pluck('media_assets.id')->map(fn ($id) => (int) $id)->all();
        $this->primaryMedia = $package->media()->wherePivot('is_primary', true)->value('media_assets.id');
        $this->team = [
            'driver_id' => (string) ($package->driver_id ?? ''),
            'guide_id' => (string) ($package->guide_id ?? ''),
            'guide_required' => (bool) $package->guide_required,
        ];
    }

    public function updatedFormTravelProviderId(): void
    {
        $contract = ProviderContract::query()
            ->where('travel_provider_id', (int) $this->form['travel_provider_id'])
            ->get()
            ->filter(fn (ProviderContract $contract) => $contract->isInForce())
            ->sortByDesc('starts_on')
            ->first();

        $this->form['provider_contract_id'] = $contract ? (string) $contract->id : '';
        $this->acceptDuplicate = false;
    }

    public function updatedFormName(): void
    {
        $this->acceptDuplicate = false;
    }

    public function addListItem(string $field): void
    {
        abort_unless(in_array($field, PackageContent::ListFields, true), 404);
        $this->form[$field][] = '';
    }

    public function removeListItem(string $field, int $index): void
    {
        abort_unless(in_array($field, PackageContent::ListFields, true), 404);
        unset($this->form[$field][$index]);
        $this->form[$field] = array_values($this->form[$field]) ?: [''];
    }

    public function addDay(): void
    {
        $this->itinerary[] = $this->blankDay();
    }

    public function removeDay(int $index): void
    {
        unset($this->itinerary[$index]);
        $this->itinerary = array_values($this->itinerary);
    }

    public function moveDay(int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);

        if (! isset($this->itinerary[$index], $this->itinerary[$target])) {
            return;
        }

        [$this->itinerary[$index], $this->itinerary[$target]] = [$this->itinerary[$target], $this->itinerary[$index]];
    }

    public function openMediaPicker(): void
    {
        $this->mediaProvider = (string) ($this->form['travel_provider_id'] ?? '');
        $this->mediaDestination = '';
        $this->showMediaPicker = true;
    }

    public function toggleMedia(int $id): void
    {
        if (in_array($id, $this->media, true)) {
            $this->removeMedia($id);

            return;
        }

        abort_unless(MediaAsset::query()->usable()->whereKey($id)->exists(), 422);
        $this->media[] = $id;
        $this->primaryMedia ??= $id;
    }

    public function removeMedia(int $id): void
    {
        $this->media = array_values(array_filter($this->media, fn (int $item) => $item !== $id));

        if ($this->primaryMedia === $id) {
            $this->primaryMedia = $this->media[0] ?? null;
        }
    }

    public function makePrimary(int $id): void
    {
        if (in_array($id, $this->media, true)) {
            $this->primaryMedia = $id;
        }
    }

    public function moveMedia(int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);

        if (isset($this->media[$index], $this->media[$target])) {
            [$this->media[$index], $this->media[$target]] = [$this->media[$target], $this->media[$index]];
        }
    }

    public function save(CreatePackage $create, SavePackage $savePackage, SyncPackageMedia $syncMedia, SetPackageTeam $setTeam, bool $submit = false): void
    {
        $user = Auth::user();

        // An untouched blank itinerary day (the form starts with one) is not an error.
        $this->itinerary = array_values(array_filter($this->itinerary, fn (array $day): bool => collect($day)->except(['day_number', 'meals'])->filter(fn ($value) => filled($value))->isNotEmpty()));

        try {
            $this->validateForm();
        } catch (ValidationException $e) {
            $this->showErrorsTab(array_keys($e->errors()));

            throw $e;
        }

        $input = $this->input();

        $this->saveValidated($user, $input, $create, $savePackage, $syncMedia, $setTeam, $submit);
    }

    /**
     * Validation errors only show on the tab that holds the field, so open the
     * tab of the first error (the page also lists every error at the top).
     *
     * @param  list<string>  $keys
     */
    private function showErrorsTab(array $keys): void
    {
        $first = $keys[0] ?? null;

        if ($first === null) {
            return;
        }

        $field = str_starts_with($first, 'form.') ? explode('.', substr($first, 5))[0] : explode('.', $first)[0];

        $this->tab = match (true) {
            $field === 'itinerary' => 'itinerary',
            $field === 'media' => 'media',
            $field === 'team' => 'team',
            in_array($field, ['currency', 'adult_price', 'child_price', 'infant_price', 'group_price', 'group_min_size', 'single_supplement', 'provider_price', 'net_provider_price', 'discount_amount', 'commission_amount'], true) => 'pricing',
            in_array($field, ['overview', 'highlights', 'inclusions', 'exclusions', 'requirements', 'what_to_bring', 'terms', 'cancellation_policy', 'refund_policy', 'meeting_point', 'pickup_info', 'dropoff_info'], true) => 'content',
            default => 'basics',
        };
    }

    private function validateForm(): void
    {
        $this->validate([
            ...PackageContent::rules('form.'),
            ...PackageContent::itineraryRules('itinerary'),
            'media' => ['array'],
            'media.*' => ['integer'],
            'team.driver_id' => ['nullable', Rule::exists('drivers', 'id')],
            'team.guide_id' => ['nullable', Rule::exists('guides', 'id')],
        ], [], $this->attributeNames());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function saveValidated(User $user, array $input, CreatePackage $create, SavePackage $savePackage, SyncPackageMedia $syncMedia, SetPackageTeam $setTeam, bool $submit): void
    {
        try {
            $package = DB::transaction(function () use ($user, $create, $savePackage, $syncMedia, $setTeam, $input): Package {
                if ($this->packageId) {
                    $package = Package::query()->findOrFail($this->packageId);
                    $savePackage->handle($user, $package, $input, $this->itinerary, $this->acceptDuplicate);
                } else {
                    $package = $create->handle($user, $input, $this->itinerary, $this->acceptDuplicate);
                }

                $syncMedia->handle($user, $package, $this->media, $this->primaryMedia);
                $setTeam->handle($user, $package, ((int) $this->team['driver_id']) ?: null, ((int) $this->team['guide_id']) ?: null, (bool) $this->team['guide_required']);

                return $package->fresh();
            });
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError(in_array($key, ['duplicate', 'package', 'media'], true) || str_starts_with($key, 'team.') ? $key : 'form.'.$key, $messages[0]);
            }

            $this->showErrorsTab(array_keys($this->getErrorBag()->toArray()));

            return;
        }

        $message = 'Package saved.';
        $version = $package->workingVersion ?? $package->liveVersion;

        if ($package->live_version_id && $package->working_version_id && $version?->material_changes) {
            $message = 'Saved as '.$version->label().'. These changes need approval; customers still see the live version.';
        } elseif ($package->live_version_id && ! $package->working_version_id) {
            $message = 'Saved. No price, itinerary, capacity or policy changes, so it was applied without approval.';
        }

        if ($submit && $package->working_version_id) {
            try {
                app(SubmitPackage::class)->handle($user, $package);
                $message = 'Saved and submitted for Sales Admin review.';
            } catch (ValidationException $e) {
                $message = collect($e->errors())->flatten()->first();
                session()->flash('toast', ['message' => $message, 'tone' => 'danger']);
                $this->redirectRoute('travel.packages.show', $package, navigate: true);

                return;
            }
        }

        session()->flash('toast', ['message' => $message]);
        $this->redirectRoute('travel.packages.show', $package, navigate: true);
    }

    public function saveAndSubmit(CreatePackage $create, SavePackage $savePackage, SyncPackageMedia $syncMedia, SetPackageTeam $setTeam): void
    {
        $missing = collect($this->readiness($this->packageId ? Package::query()->find($this->packageId) : null))->reject(fn (array $item) => $item['ok'])->pluck('label');

        if ($missing->isNotEmpty()) {
            $this->resetErrorBag();
            $this->addError('package', 'Not submitted yet. Complete these first: '.$missing->implode(', ').'. You can save it as a draft meanwhile.');

            return;
        }

        $this->save($create, $savePackage, $syncMedia, $setTeam, submit: true);
    }

    public function render(): View
    {
        $user = Auth::user();
        $package = $this->packageId ? Package::query()->with(['liveVersion', 'workingVersion'])->find($this->packageId) : null;
        $providerId = (int) ($this->form['travel_provider_id'] ?? 0);

        return view('livewire.travel.packages.form', [
            'package' => $package,
            'providers' => TravelProvider::query()->current()->orderBy('name')->get(['id', 'name', 'status']),
            'contracts' => $providerId ? ProviderContract::query()->where('travel_provider_id', $providerId)->orderByDesc('starts_on')->get() : collect(),
            'readiness' => $this->readiness($package),
            'duplicates' => $this->duplicates(),
            'selectedMedia' => $this->media === [] ? collect() : MediaAsset::query()->whereKey($this->media)->get()->sortBy(fn (MediaAsset $asset) => array_search($asset->id, $this->media, true))->values(),
            'pickerMedia' => $this->showMediaPicker ? $this->pickerMedia() : collect(),
            'mediaDestinations' => $this->showMediaPicker ? MediaAsset::query()->usable()->whereNotNull('destination')->distinct()->orderBy('destination')->pluck('destination') : collect(),
            'drivers' => Driver::query()->where(fn ($query) => $query->where('status', ResourceStatus::Active)->orWhere('id', (int) $this->team['driver_id']))->orderBy('name')->get(['id', 'name', 'vehicle', 'vehicle_registration']),
            'guides' => Guide::query()->where(fn ($query) => $query->where('status', ResourceStatus::Active)->orWhere('id', (int) $this->team['guide_id']))->orderBy('name')->get(['id', 'name', 'languages']),
            'showFinancials' => PackageContent::mayEditFinancials($user, $package),
            'materialPreview' => $this->materialPreview($package),
            'galleryUrl' => Route::has('travel.media.index') ? route('travel.media.index') : null,
            'categories' => MediaCategory::cases(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        $input = $this->form;

        foreach (['travel_provider_id', 'provider_contract_id', 'days', 'nights', 'min_travelers', 'max_travelers', 'default_capacity', 'min_age', 'group_min_size'] as $field) {
            $input[$field] = ($input[$field] ?? '') === '' ? null : (int) $input[$field];
        }

        foreach (['adult_price', 'child_price', 'infant_price', 'group_price', 'single_supplement', 'provider_price', 'net_provider_price', 'discount_amount', 'commission_amount'] as $field) {
            $input[$field] = ($input[$field] ?? '') === '' ? null : $input[$field];
        }

        return $input;
    }

    /**
     * Live readiness preview from what is on screen (nothing saved yet).
     *
     * @return list<array{key: string, label: string, ok: bool}>
     */
    private function readiness(?Package $package): array
    {
        $content = PackageContent::normalise($this->input(), [], Auth::user(), $package);

        // Half-typed numbers (\"1,000\", \"abc\") are reported by validation on save;
        // the preview just treats them as empty instead of failing.
        foreach ((new PackageVersion)->getCasts() as $field => $cast) {
            if ((str_starts_with((string) $cast, 'decimal') || $cast === 'integer') && isset($content[$field]) && ! is_numeric($content[$field])) {
                $content[$field] = null;
            }
        }

        $version = new PackageVersion($content);
        $version->setRelation('itineraryDays', collect(PackageContent::normaliseItinerary(array_filter($this->itinerary, fn ($day) => filled($day['title'] ?? null))))->map(fn ($day) => new PackageItineraryDay($day)));

        $usable = $this->media === [] ? 0 : MediaAsset::query()->usable()->whereKey($this->media)->count();

        return array_map(fn (array $item) => $item['key'] === 'media' ? [...$item, 'ok' => $usable > 0] : $item, PackageReadiness::check($package ?? new Package, $version));
    }

    /**
     * @return Collection<int, array{package: Package, exact: bool, reason: string}>
     */
    private function duplicates(): Collection
    {
        if (blank($this->form['name'] ?? null) || blank($this->form['travel_provider_id'] ?? null)) {
            return collect();
        }

        return PackageDuplicateFinder::find((string) $this->form['name'], (int) $this->form['travel_provider_id'], $this->form['destination'] ?? null, $this->packageId);
    }

    /**
     * Material differences from the live version, for the warning banner.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function materialPreview(?Package $package): array
    {
        if (! $package?->liveVersion) {
            return [];
        }

        $content = PackageContent::normalise($this->input(), PackageContent::contentOf($package->liveVersion), Auth::user(), $package);

        return PackageContent::materialChanges($package->liveVersion, $content, PackageContent::normaliseItinerary($this->itinerary));
    }

    /**
     * @return Collection<int, MediaAsset>
     */
    private function pickerMedia(): Collection
    {
        return MediaAsset::query()
            ->usable()
            ->with('provider:id,name')
            ->when($this->mediaSearch !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'like', '%'.$this->mediaSearch.'%')
                ->orWhere('destination', 'like', '%'.$this->mediaSearch.'%')
                ->orWhere('tags', 'like', '%'.$this->mediaSearch.'%')))
            ->when($this->mediaProvider !== '', fn ($query) => $query->where('travel_provider_id', (int) $this->mediaProvider))
            ->when($this->mediaDestination !== '', fn ($query) => $query->where('destination', $this->mediaDestination))
            ->when($this->mediaCategory !== '', fn ($query) => $query->where('category', $this->mediaCategory))
            ->latest()
            ->limit(60)
            ->get();
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        $names = [];

        foreach (PackageContent::Fields as $field) {
            $names['form.'.$field] = str_replace(['_id', '_'], ['', ' '], $field);
        }

        $names['itinerary.*.title'] = 'day title';

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function blankForm(): array
    {
        $form = array_fill_keys(PackageContent::Fields, '');

        foreach (PackageContent::ListFields as $field) {
            $form[$field] = [''];
        }

        return [...$form, 'package_type' => 'safari', 'country' => 'Kenya', 'currency' => config('travel.currency'), 'days' => '1', 'nights' => '0', 'min_travelers' => '1'];
    }

    /**
     * @return array<string, mixed>
     */
    private function blankDay(): array
    {
        return ['title' => '', 'description' => '', 'activities' => '', 'meals' => [], 'accommodation' => '', 'transport' => '', 'notes' => ''];
    }
}
