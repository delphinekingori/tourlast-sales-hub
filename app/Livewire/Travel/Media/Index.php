<?php

namespace App\Livewire\Travel\Media;

use App\Actions\Travel\Media\ArchiveMediaAsset;
use App\Actions\Travel\Media\ChangeMediaPermission;
use App\Actions\Travel\Media\DeleteMediaAsset;
use App\Actions\Travel\Media\StoreMediaAsset;
use App\Actions\Travel\Media\UpdateMediaAsset;
use App\Enums\Travel\MediaCategory;
use App\Enums\Travel\MediaUsagePermission;
use App\Enums\Travel\PackageStatus;
use App\Models\MediaAsset;
use App\Models\TravelProvider;
use App\Models\User;
use App\Support\Travel\MediaRules;
use App\Support\Travel\TravelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * The shared Media Gallery: upload provider images, videos and documents
 * once, then reuse them on any package.
 */
#[Title('Media Gallery')]
class Index extends Component
{
    use WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $provider = '';

    #[Url]
    public string $destination = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $permission = '';

    #[Url]
    public string $uploader = '';

    #[Url]
    public bool $archived = false;

    /** @var list<int> */
    public array $selected = [];

    /** @var array{category: string, provider: string, destination: string, tag: string} */
    public array $bulk = ['category' => '', 'provider' => '', 'destination' => '', 'tag' => ''];

    public bool $showUpload = false;

    /** @var array<int, mixed> */
    public array $uploads = [];

    /** @var array<int, string> */
    public array $titles = [];

    /** @var array<string, string> */
    public array $meta = [];

    #[Locked]
    public ?int $assetId = null;

    public bool $showDetail = false;

    /** @var array<string, string> */
    public array $form = [];

    public bool $showArchive = false;

    /** @var list<int> */
    #[Locked]
    public array $archiveIds = [];

    public string $archiveConfirmation = '';

    public bool $showDelete = false;

    #[Locked]
    public ?int $deleteId = null;

    public function mount(): void
    {
        TravelAccess::abortUnlessWorks($this->user());
        $this->resetMeta();
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'provider', 'destination', 'category', 'permission', 'uploader', 'archived'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'provider', 'destination', 'category', 'permission', 'uploader', 'archived', 'selected']);
        $this->resetPage();
    }

    // ── Upload ───────────────────────────────────────────────────────────

    public function openUpload(): void
    {
        TravelAccess::abortUnlessWorks($this->user());
        $this->resetValidation();
        $this->reset(['uploads', 'titles']);
        $this->resetMeta();
        $this->showUpload = true;
    }

    public function updatedUploads(): void
    {
        $this->validateOnly('uploads.*', $this->uploadRules());

        foreach ($this->uploads as $index => $file) {
            $this->titles[$index] ??= StoreMediaAsset::titleFromFilename($file->getClientOriginalName());
        }
    }

    /**
     * Drop one file from the upload list (not Livewire's own removeUpload,
     * which has a different signature).
     */
    public function discardUpload(int $index): void
    {
        unset($this->uploads[$index], $this->titles[$index]);
        $this->uploads = array_values($this->uploads);
        $this->titles = array_values($this->titles);
    }

    /**
     * Save the selected files to the gallery. (Not named upload: that is
     * Livewire's own JavaScript upload helper and would be called instead.)
     */
    public function addToGallery(StoreMediaAsset $store): void
    {
        $user = $this->user();
        TravelAccess::abortUnlessWorks($user);

        $this->validate($this->uploadRules() + [
            'meta.travel_provider_id' => ['nullable', Rule::exists('travel_providers', 'id')],
            'meta.destination' => ['nullable', 'string', 'max:120'],
            'meta.category' => ['required', Rule::enum(MediaCategory::class)],
            'meta.tags' => ['nullable', 'string', 'max:255'],
            'meta.source' => ['nullable', 'string', 'max:255'],
            'meta.copyright_owner' => ['nullable', 'string', 'max:255'],
            'meta.usage_permission' => ['required', Rule::enum(MediaUsagePermission::class)],
            'meta.usage_notes' => ['nullable', 'string', 'max:1000'],
            'titles.*' => ['nullable', 'string', 'max:255'],
        ], [], ['uploads.*' => 'file', 'meta.category' => 'category']);

        $count = 0;

        foreach ($this->uploads as $index => $file) {
            $store->handle($file, $this->meta + ['title' => $this->titles[$index] ?? null], $user);
            $count++;
        }

        $this->showUpload = false;
        $this->reset(['uploads', 'titles']);
        $this->resetPage();
        $this->dispatch('toast', message: $count.' '.str('file')->plural($count).' added to the Media Gallery.');
    }

    // ── Detail ───────────────────────────────────────────────────────────

    public function open(int $id): void
    {
        $user = $this->user();
        TravelAccess::abortUnlessWorks($user);
        $asset = MediaAsset::query()->findOrFail($id);

        $this->resetValidation();
        $this->assetId = $asset->id;
        $this->form = [
            'title' => $asset->title,
            'description' => (string) $asset->description,
            'alt_text' => (string) $asset->alt_text,
            'travel_provider_id' => (string) ($asset->travel_provider_id ?? ''),
            'destination' => (string) $asset->destination,
            'category' => $asset->category->value,
            'tags' => implode(', ', $asset->tags ?? []),
            'source' => (string) $asset->source,
            'copyright_owner' => (string) $asset->copyright_owner,
            'usage_permission' => $asset->usage_permission->value,
            'usage_notes' => (string) $asset->usage_notes,
        ];
        $this->showDetail = true;
    }

    public function saveDetail(UpdateMediaAsset $update, ChangeMediaPermission $changePermission): void
    {
        $user = $this->user();
        $asset = $this->asset();
        abort_unless(MediaRules::canEdit($user, $asset), 403);

        $this->validate([
            'form.title' => ['required', 'string', 'max:255'],
            'form.description' => ['nullable', 'string', 'max:2000'],
            'form.alt_text' => ['nullable', 'string', 'max:255'],
            'form.travel_provider_id' => ['nullable', Rule::exists('travel_providers', 'id')],
            'form.destination' => ['nullable', 'string', 'max:120'],
            'form.category' => ['required', Rule::enum(MediaCategory::class)],
            'form.tags' => ['nullable', 'string', 'max:255'],
            'form.source' => ['nullable', 'string', 'max:255'],
            'form.copyright_owner' => ['nullable', 'string', 'max:255'],
            'form.usage_permission' => ['required', Rule::enum(MediaUsagePermission::class)],
            'form.usage_notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['form.title' => 'title', 'form.category' => 'category']);

        $update->handle($asset, collect($this->form)->only(UpdateMediaAsset::Fields)->except('usage_notes')->all(), $user);

        $newPermission = MediaUsagePermission::from($this->form['usage_permission']);

        if (MediaRules::canChangePermission($user)) {
            $changePermission->handle($asset->refresh(), $newPermission, $user, $this->form['usage_notes']);
        } elseif ($newPermission !== $asset->usage_permission) {
            abort(403);
        } else {
            $update->handle($asset, ['usage_notes' => $this->form['usage_notes']], $user);
        }

        $this->showDetail = false;
        $this->dispatch('toast', message: 'File details saved.');
    }

    // ── Archive, restore, delete ─────────────────────────────────────────

    public function archiveOne(int $id): void
    {
        $this->startArchive([$id]);
    }

    public function restore(int $id, ArchiveMediaAsset $archive): void
    {
        $archive->restore(MediaAsset::query()->findOrFail($id), $this->user());
        $this->dispatch('toast', message: 'File restored to the gallery.');
    }

    public function archive(ArchiveMediaAsset $archive): void
    {
        $user = $this->user();
        $done = 0;

        foreach (MediaAsset::query()->whereKey($this->archiveIds)->withUsageCounts()->get() as $asset) {
            if (MediaRules::canArchive($user, $asset)) {
                $archive->archive($asset, $user, $this->archiveConfirmation);
                $done++;
            }
        }

        $this->showArchive = false;
        $this->showDetail = false;
        $this->selected = [];
        $this->archiveIds = [];
        $this->dispatch('toast', message: $done.' '.str('file')->plural($done).' archived.');
    }

    public function confirmDelete(int $id): void
    {
        $asset = MediaAsset::query()->withUsageCounts()->findOrFail($id);
        abort_unless(MediaRules::canDelete($this->user(), $asset), 403);

        $this->deleteId = $asset->id;
        $this->showDelete = true;
    }

    public function delete(DeleteMediaAsset $delete): void
    {
        $delete->handle(MediaAsset::query()->findOrFail($this->deleteId), $this->user());

        $this->showDelete = false;
        $this->showDetail = false;
        $this->deleteId = null;
        $this->selected = array_values(array_diff($this->selected, [$this->assetId]));
        $this->dispatch('toast', message: 'File deleted.');
    }

    // ── Bulk ─────────────────────────────────────────────────────────────

    /**
     * @param  list<int|string>  $ids
     */
    public function selectPage(array $ids): void
    {
        $this->selected = MediaAsset::query()->whereKey(array_map('intval', $ids))->pluck('id')->all();
    }

    public function bulkApply(UpdateMediaAsset $update): void
    {
        $user = $this->user();
        TravelAccess::abortUnlessWorks($user);

        $this->validate([
            'bulk.category' => ['nullable', Rule::enum(MediaCategory::class)],
            'bulk.provider' => ['nullable', Rule::exists('travel_providers', 'id')],
            'bulk.destination' => ['nullable', 'string', 'max:120'],
            'bulk.tag' => ['nullable', 'string', 'max:40'],
        ]);

        $changes = array_filter([
            'category' => $this->bulk['category'],
            'travel_provider_id' => $this->bulk['provider'],
            'destination' => $this->bulk['destination'],
        ]);

        if ($changes === [] && trim($this->bulk['tag']) === '') {
            return;
        }

        [$done, $skipped] = [0, 0];

        foreach (MediaAsset::query()->whereKey($this->selected)->get() as $asset) {
            if (! MediaRules::canEdit($user, $asset)) {
                $skipped++;

                continue;
            }

            $data = $changes;

            if (trim($this->bulk['tag']) !== '') {
                $data['tags'] = array_merge($asset->tags ?? [], [$this->bulk['tag']]);
            }

            $update->handle($asset, $data, $user);
            $done++;
        }

        $this->bulk = ['category' => '', 'provider' => '', 'destination' => '', 'tag' => ''];
        $this->dispatch('toast', message: $done.' '.str('file')->plural($done).' updated'.($skipped ? ", {$skipped} skipped (uploaded by someone else)" : '').'.', tone: $skipped ? 'warning' : 'success');
    }

    public function bulkArchive(): void
    {
        $this->startArchive($this->selected);
    }

    public function bulkRevoke(ChangeMediaPermission $changePermission): void
    {
        $user = $this->user();
        abort_unless(MediaRules::canChangePermission($user), 403);

        $assets = MediaAsset::query()->whereKey($this->selected)->get();

        foreach ($assets as $asset) {
            $changePermission->handle($asset, MediaUsagePermission::Revoked, $user);
        }

        $this->selected = [];
        $this->dispatch('toast', message: 'Usage permission revoked on '.$assets->count().' '.str('file')->plural($assets->count()).'. They stay on existing packages but can no longer be chosen.');
    }

    public function render(): View
    {
        $user = $this->user();
        $assets = $this->filtered()
            ->with(['provider:id,name', 'uploader:id,name'])
            ->withUsageCounts()
            ->latest()
            ->latest('id')
            ->paginate(24);

        $detail = $this->showDetail && $this->assetId
            ? MediaAsset::query()->with(['provider:id,name', 'uploader:id,name', 'packages' => fn ($query) => $query->select('packages.id', 'packages.name', 'packages.reference', 'packages.status')])->withUsageCounts()->find($this->assetId)
            : null;

        return view('livewire.travel.media.index', [
            'assets' => $assets,
            'summary' => $this->summary(),
            'providers' => TravelProvider::query()->current()->orderBy('name')->get(['id', 'name']),
            'destinations' => MediaAsset::query()->whereNotNull('destination')->distinct()->orderBy('destination')->pluck('destination'),
            'uploaders' => User::query()->whereIn('id', MediaAsset::query()->select('uploaded_by')->whereNotNull('uploaded_by'))->orderBy('name')->get(['id', 'name']),
            'categories' => MediaCategory::options(),
            'permissions' => MediaUsagePermission::options(),
            'detail' => $detail,
            'canEditDetail' => $detail && MediaRules::canEdit($user, $detail),
            'canDeleteDetail' => $detail && MediaRules::canDelete($user, $detail),
            'canChangePermission' => MediaRules::canChangePermission($user),
            'isManager' => TravelAccess::managesAll($user),
            'archiveNeedsConfirmation' => $this->showArchive && MediaAsset::query()->whereKey($this->archiveIds)->whereHas('packages', fn (Builder $query) => $query->where('packages.status', PackageStatus::Published))->exists(),
            'confirmationPhrase' => MediaRules::ArchiveConfirmation,
            'deleting' => $this->showDelete && $this->deleteId ? MediaAsset::query()->find($this->deleteId) : null,
            'hasFilters' => $this->search !== '' || $this->provider !== '' || $this->destination !== '' || $this->category !== '' || $this->permission !== '' || $this->uploader !== '' || $this->archived,
        ]);
    }

    /**
     * @return Builder<MediaAsset>
     */
    private function filtered(): Builder
    {
        return MediaAsset::query()
            ->search($this->search)
            ->when($this->provider !== '', fn (Builder $query) => $query->where('travel_provider_id', (int) $this->provider))
            ->when($this->destination !== '', fn (Builder $query) => $query->where('destination', $this->destination))
            ->when($this->category !== '', fn (Builder $query) => $query->where('category', $this->category))
            ->when($this->permission !== '', fn (Builder $query) => $query->where('usage_permission', $this->permission))
            ->when($this->uploader !== '', fn (Builder $query) => $query->where('uploaded_by', (int) $this->uploader))
            ->when($this->archived, fn (Builder $query) => $query->whereNotNull('archived_at'), fn (Builder $query) => $query->whereNull('archived_at'));
    }

    /**
     * @return array{total: int, images: int, other: int, used: int, revoked: int, archived: int}
     */
    private function summary(): array
    {
        $row = MediaAsset::query()->selectRaw(
            "sum(case when archived_at is null then 1 else 0 end) as total,
             sum(case when archived_at is null and kind = 'image' then 1 else 0 end) as images,
             sum(case when archived_at is null and kind != 'image' then 1 else 0 end) as other,
             sum(case when archived_at is null and usage_permission = 'revoked' then 1 else 0 end) as revoked,
             sum(case when archived_at is not null then 1 else 0 end) as archived"
        )->first();

        return [
            'total' => (int) $row?->total,
            'images' => (int) $row?->images,
            'other' => (int) $row?->other,
            'used' => (int) DB::table('package_media')->join('media_assets', 'media_assets.id', '=', 'package_media.media_asset_id')->whereNull('media_assets.archived_at')->distinct()->count('package_media.media_asset_id'),
            'revoked' => (int) $row?->revoked,
            'archived' => (int) $row?->archived,
        ];
    }

    /**
     * @param  list<int>  $ids
     */
    private function startArchive(array $ids): void
    {
        $user = $this->user();
        $assets = MediaAsset::query()->whereKey($ids)->withUsageCounts()->get();
        abort_if($assets->isEmpty(), 404);
        abort_unless($assets->every(fn (MediaAsset $asset): bool => MediaRules::canEdit($user, $asset)), 403);

        $this->resetValidation();
        $this->archiveIds = $assets->pluck('id')->all();
        $this->archiveConfirmation = '';
        $this->showArchive = true;
    }

    private function asset(): MediaAsset
    {
        return MediaAsset::query()->findOrFail($this->assetId);
    }

    private function resetMeta(): void
    {
        $this->meta = [
            'travel_provider_id' => '',
            'destination' => '',
            'category' => MediaCategory::Other->value,
            'tags' => '',
            'source' => '',
            'copyright_owner' => '',
            'usage_permission' => MediaUsagePermission::Granted->value,
            'usage_notes' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadRules(): array
    {
        return [
            'uploads' => ['required', 'array', 'min:1', 'max:20'],
            'uploads.*' => [
                'file',
                'mimetypes:'.implode(',', array_keys(StoreMediaAsset::Types)),
                function (string $attribute, mixed $file, \Closure $fail): void {
                    $type = StoreMediaAsset::Types[$file->getMimeType()] ?? null;

                    if ($type && $file->getSize() > $type[1] * 1024) {
                        $fail($file->getClientOriginalName().' is larger than '.($type[1] / 1024).' MB (images 8 MB, PDFs 10 MB, videos 50 MB).');
                    }
                },
            ],
        ];
    }

    private function user(): User
    {
        return Auth::user();
    }
}
