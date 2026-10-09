@php
    $selectClass = 'h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
    $pageIds = $assets->pluck('id')->all();
    $kindIcon = ['image' => 'photo', 'video' => 'activity', 'document' => 'document'];
@endphp

<div class="grid gap-5"
    x-data="{
        view: (() => { try { return localStorage.getItem('tl-media-view') ?? 'grid' } catch (e) { return 'grid' } })(),
        setView(value) { this.view = value; try { localStorage.setItem('tl-media-view', value) } catch (e) {} },
    }"
>
    <x-ui.page-header title="Media Gallery" description="Provider photos, videos and documents, uploaded once and reused on any package. Check the usage permission before using a file.">
        <x-slot:actions>
            <x-ui.button icon="plus" wire:click="openUpload">Upload files</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Summary --}}
    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-3 xl:grid-cols-6">
        @foreach ([
            ['Files in the gallery', $summary['total'], 'text-ink'],
            ['Images', $summary['images'], 'text-ink'],
            ['Videos & documents', $summary['other'], 'text-ink'],
            ['Used on packages', $summary['used'], 'text-brand-text'],
            ['Permission revoked', $summary['revoked'], $summary['revoked'] ? 'text-danger' : 'text-ink'],
            ['Archived', $summary['archived'], 'text-ink-muted'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular text-xl leading-tight font-bold {{ $tone }}">{{ number_format($value) }}</dd>
            </div>
        @endforeach
    </dl>

    {{-- Search and filters --}}
    <div class="grid gap-3 rounded-xl border border-line bg-surface p-3 shadow-card">
        <div class="flex flex-wrap items-center gap-2">
            <div class="min-w-56 flex-1">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search title, description, tags, file name..." wide aria-label="Search the Media Gallery" />
            </div>
            <select wire:model.live="provider" aria-label="Provider" class="{{ $selectClass }}">
                <option value="">All providers</option>
                @foreach ($providers as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="destination" aria-label="Destination" class="{{ $selectClass }}">
                <option value="">All destinations</option>
                @foreach ($destinations as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
            <select wire:model.live="category" aria-label="Category" class="{{ $selectClass }}">
                <option value="">All categories</option>
                @foreach ($categories as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="permission" aria-label="Usage permission" class="{{ $selectClass }}">
                <option value="">Any permission</option>
                @foreach ($permissions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="uploader" aria-label="Uploaded by" class="{{ $selectClass }}">
                <option value="">Anyone</option>
                @foreach ($uploaders as $person)
                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                @endforeach
            </select>
            <label class="flex h-9 cursor-pointer items-center gap-2 rounded-md px-2 text-[13px] text-ink-muted hover:bg-surface-muted">
                <input type="checkbox" wire:model.live="archived" class="size-3.5 accent-[var(--tl-brand)]"> Archived
            </label>
            @if ($hasFilters)
                <x-ui.button variant="ghost" size="sm" wire:click="clearFilters">Clear</x-ui.button>
            @endif
            <div class="ml-auto inline-flex rounded-md border border-line bg-surface p-0.5" role="radiogroup" aria-label="View">
                @foreach (['grid' => 'Grid', 'list' => 'List'] as $value => $label)
                    <button type="button" x-on:click="setView('{{ $value }}')"
                        x-bind:class="view === '{{ $value }}' ? 'bg-brand-soft text-brand-text' : 'text-ink-subtle hover:text-ink'"
                        x-bind:aria-checked="view === '{{ $value }}'" role="radio"
                        class="rounded px-2.5 py-1 text-xs font-medium transition-colors">{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Bulk actions --}}
    @if ($selected !== [])
        <div class="flex flex-wrap items-end gap-2 rounded-xl border border-brand/30 bg-brand-soft/40 px-3 py-2.5">
            <p class="mr-2 self-center text-[13px] font-semibold text-ink">{{ count($selected) }} selected</p>
            <select wire:model="bulk.category" aria-label="Set category" class="{{ $selectClass }}">
                <option value="">Set category…</option>
                @foreach ($categories as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model="bulk.provider" aria-label="Set provider" class="{{ $selectClass }}">
                <option value="">Set provider…</option>
                @foreach ($providers as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
            <input type="text" wire:model="bulk.destination" list="media-destinations" placeholder="Set destination…" aria-label="Set destination" class="{{ $selectClass }} w-44">
            <input type="text" wire:model="bulk.tag" placeholder="Add tag…" aria-label="Add tag" class="{{ $selectClass }} w-32">
            <x-ui.button variant="secondary" wire:click="bulkApply">Apply</x-ui.button>
            <span class="mx-1 h-6 w-px self-center bg-line"></span>
            <x-ui.button variant="secondary" icon="register" wire:click="bulkArchive">Archive</x-ui.button>
            @if ($canChangePermission)
                <x-ui.button variant="danger-ghost" icon="ban" wire:click="bulkRevoke" wire:confirm="Revoke usage permission on the selected files? They stay on existing packages but can no longer be chosen.">Revoke permission</x-ui.button>
            @endif
            <x-ui.button variant="ghost" class="ml-auto" wire:click="$set('selected', [])">Clear selection</x-ui.button>
        </div>
    @endif

    @if ($assets->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="photo" :title="$hasFilters ? 'No files match these filters' : 'The Media Gallery is empty'" :description="$hasFilters ? 'Try clearing a filter.' : 'Upload provider images once and reuse them on every package.'">
                @unless ($hasFilters)
                    <x-ui.button icon="plus" wire:click="openUpload">Upload files</x-ui.button>
                @endunless
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="flex items-center justify-between gap-2 text-xs text-ink-subtle">
            <label class="flex cursor-pointer items-center gap-2">
                <input type="checkbox" class="size-3.5 accent-[var(--tl-brand)]"
                    @checked($pageIds !== [] && array_diff($pageIds, $selected) === [])
                    x-on:change="$event.target.checked ? $wire.selectPage(@js($pageIds)) : $wire.set('selected', [])"> Select this page
            </label>
            <span>{{ number_format($assets->total()) }} {{ str('file')->plural($assets->total()) }}</span>
        </div>

        {{-- Grid view --}}
        <div x-show="view === 'grid'" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6" wire:loading.delay.class="opacity-60">
            @foreach ($assets as $asset)
                @php $usage = $asset->usage(); @endphp
                <article wire:key="media-grid-{{ $asset->id }}" @class([
                    'group relative min-w-0 overflow-hidden rounded-xl border bg-surface shadow-card',
                    'border-brand ring-2 ring-brand-soft' => in_array($asset->id, $selected, true),
                    'border-line' => ! in_array($asset->id, $selected, true),
                ])>
                    <label class="absolute top-2 left-2 z-10 grid size-6 cursor-pointer place-items-center rounded bg-surface/90 shadow-xs">
                        <span class="sr-only">Select {{ $asset->title }}</span>
                        <input type="checkbox" value="{{ $asset->id }}" wire:model.live="selected" class="size-3.5 accent-[var(--tl-brand)]">
                    </label>
                    <button type="button" wire:click="open({{ $asset->id }})" class="block w-full text-left">
                        <div class="relative aspect-[4/3] bg-surface-muted">
                            @if ($asset->isImage())
                                <img src="{{ $asset->url() }}" alt="{{ $asset->alt_text ?? $asset->title }}" loading="lazy" class="size-full object-cover @if ($asset->isRevoked()) opacity-50 grayscale @endif">
                            @else
                                <span class="grid size-full place-items-center text-ink-subtle">
                                    <x-ui.icon :name="$kindIcon[$asset->kind] ?? 'document'" class="size-8" />
                                </span>
                            @endif
                            @if ($asset->isRevoked())
                                <x-ui.pill tone="danger" class="absolute right-2 bottom-2">Permission revoked</x-ui.pill>
                            @elseif ($asset->usage_permission->value === 'restricted')
                                <x-ui.pill tone="warning" class="absolute right-2 bottom-2">Restricted</x-ui.pill>
                            @endif
                        </div>
                        <div class="grid gap-0.5 px-3 py-2">
                            <p class="truncate text-[13px] font-semibold text-ink">{{ $asset->title }}</p>
                            <p class="truncate text-xs text-ink-subtle">{{ $asset->provider?->name ?? 'No provider' }}{{ $asset->destination ? ' · '.$asset->destination : '' }}</p>
                            <p class="flex items-center justify-between gap-2 pt-1 text-[11.5px] text-ink-subtle">
                                <span>{{ $asset->category->label() }}</span>
                                <span @class(['font-medium text-brand-text' => $usage['packages'] > 0])>{{ $usage['packages'] ? 'Used in '.$usage['packages'].' '.str('package')->plural($usage['packages']) : 'Not used yet' }}</span>
                            </p>
                        </div>
                    </button>
                </article>
            @endforeach
        </div>

        {{-- List view --}}
        <div x-show="view === 'list'" x-cloak>
            <x-ui.table-card :sticky="false">
                <table class="w-full text-left text-[13px]">
                    <thead>
                        <tr>
                            <th class="w-8"><span class="sr-only">Select</span></th>
                            <th>FILE</th>
                            <th>PROVIDER</th>
                            <th>DESTINATION</th>
                            <th>CATEGORY</th>
                            <th>PERMISSION</th>
                            <th>USED IN</th>
                            <th>UPLOADED</th>
                            <th class="text-right">SIZE</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($assets as $asset)
                            @php $usage = $asset->usage(); @endphp
                            <tr wire:key="media-list-{{ $asset->id }}">
                                <td><input type="checkbox" value="{{ $asset->id }}" wire:model.live="selected" class="size-3.5 accent-[var(--tl-brand)]" aria-label="Select {{ $asset->title }}"></td>
                                <td>
                                    <button type="button" wire:click="open({{ $asset->id }})" class="flex min-w-0 items-center gap-3 text-left">
                                        <span class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-md bg-surface-muted text-ink-subtle">
                                            @if ($asset->isImage())
                                                <img src="{{ $asset->url() }}" alt="" loading="lazy" class="size-full object-cover">
                                            @else
                                                <x-ui.icon :name="$kindIcon[$asset->kind] ?? 'document'" class="size-5" />
                                            @endif
                                        </span>
                                        <span class="grid min-w-0 leading-tight">
                                            <span class="truncate font-semibold text-ink">{{ $asset->title }}</span>
                                            <span class="truncate text-xs text-ink-subtle">{{ $asset->original_name }}</span>
                                        </span>
                                    </button>
                                </td>
                                <td class="text-ink-muted">{{ $asset->provider?->name ?? '—' }}</td>
                                <td class="text-ink-muted">{{ $asset->destination ?? '—' }}</td>
                                <td class="text-ink-muted">{{ $asset->category->label() }}</td>
                                <td><x-ui.pill :tone="$asset->usage_permission->tone()">{{ $asset->usage_permission->label() }}</x-ui.pill></td>
                                <td class="whitespace-nowrap text-ink-muted">
                                    {{ $usage['packages'] ? $usage['packages'].' '.str('package')->plural($usage['packages']) : '—' }}
                                    @if ($usage['published'])<span class="text-xs text-success">({{ $usage['published'] }} published)</span>@endif
                                </td>
                                <td class="whitespace-nowrap text-ink-muted">{{ $asset->uploader?->name ?? '—' }} · {{ $asset->created_at->format('j M Y') }}</td>
                                <td class="tabular text-right whitespace-nowrap text-ink-muted">{{ $asset->sizeLabel() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.table-card>
        </div>

        @if ($assets->hasPages())
            <div>{{ $assets->links() }}</div>
        @endif
    @endif

    <datalist id="media-destinations">
        @foreach ($destinations as $option)<option value="{{ $option }}">@endforeach
    </datalist>

    {{-- Upload --}}
    <x-ui.modal wire:model="showUpload" title="Upload to the Media Gallery" description="JPG, PNG or WebP images up to 8 MB, MP4 videos up to 50 MB, PDF documents up to 10 MB. Up to 20 files at a time." max-width="max-w-3xl">
        <form wire:submit="addToGallery" id="media-upload-form" class="grid gap-4">
            <label class="grid cursor-pointer justify-items-center gap-1.5 rounded-lg border border-dashed border-line-strong bg-surface-muted/50 px-4 py-6 text-center hover:border-brand">
                <x-ui.icon name="photo" class="size-6 text-ink-subtle" />
                <span class="text-[13px] font-medium text-ink">Choose files</span>
                <span class="text-xs text-ink-subtle">The details below apply to every file in this upload.</span>
                <input type="file" multiple wire:model="uploads" accept="image/jpeg,image/png,image/webp,video/mp4,application/pdf" class="sr-only">
            </label>
            <p wire:loading wire:target="uploads" class="text-xs text-ink-subtle">Uploading…</p>
            @error('uploads')<p class="text-xs text-danger">{{ $message }}</p>@enderror
            @foreach ($errors->get('uploads.*') as $messages)
                @foreach ($messages as $message)<p class="text-xs text-danger">{{ $message }}</p>@endforeach
            @endforeach

            @if ($uploads !== [])
                <ul class="grid max-h-56 gap-1.5 overflow-y-auto">
                    @foreach ($uploads as $index => $file)
                        <li wire:key="upload-{{ $index }}" class="flex items-center gap-3 rounded-md border border-line px-2 py-1.5">
                            <span class="grid size-9 shrink-0 place-items-center overflow-hidden rounded bg-surface-muted text-ink-subtle">
                                @if (str_starts_with((string) $file->getMimeType(), 'image/') && $file->isPreviewable())
                                    <img src="{{ $file->temporaryUrl() }}" alt="" class="size-full object-cover">
                                @else
                                    <x-ui.icon name="document" class="size-4" />
                                @endif
                            </span>
                            <input type="text" wire:model="titles.{{ $index }}" aria-label="Title for {{ $file->getClientOriginalName() }}" class="{{ $selectClass }} min-w-0 flex-1">
                            <span class="hidden w-40 truncate text-xs text-ink-subtle sm:block">{{ $file->getClientOriginalName() }}</span>
                            <button type="button" wire:click="discardUpload({{ $index }})" class="rounded p-1 text-ink-subtle hover:bg-surface-muted hover:text-danger" aria-label="Remove {{ $file->getClientOriginalName() }}"><x-ui.icon name="x" class="size-4" /></button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.select label="Provider" wire:model="meta.travel_provider_id" id="upload-provider">
                    <option value="">No provider</option>
                    @foreach ($providers as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.input label="Destination" wire:model="meta.destination" list="media-destinations" id="upload-destination" placeholder="e.g. Masai Mara" />
                <x-ui.select label="Category" wire:model="meta.category" id="upload-category">
                    @foreach ($categories as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </x-ui.select>
                <x-ui.input label="Tags" wire:model="meta.tags" id="upload-tags" placeholder="lions, sunrise, game drive" hint="Separate with commas." />
                <x-ui.input label="Source" wire:model="meta.source" id="upload-source" placeholder="e.g. Provider supplied, Tourlast shoot" />
                <x-ui.input label="Copyright / owner" wire:model="meta.copyright_owner" id="upload-owner" placeholder="Who owns the rights" />
                <x-ui.select label="Usage permission" wire:model="meta.usage_permission" id="upload-permission">
                    @foreach ($permissions as $value => $label)
                        @continue($value === 'revoked')
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Usage notes" wire:model="meta.usage_notes" id="upload-notes" placeholder="e.g. Only on Tourlast channels, credit the provider" />
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="media-upload-form" wire:loading.attr="disabled" wire:target="addToGallery,uploads">Add to gallery</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Detail --}}
    <x-ui.slide-over wire:model="showDetail" :title="$detail?->title ?? 'File'" :description="$detail ? $detail->original_name.' · '.$detail->sizeLabel() : null">
        @if ($detail)
            @php $usage = $detail->usage(); @endphp
            <div class="grid gap-4">
                <div class="overflow-hidden rounded-lg border border-line bg-surface-muted">
                    @if ($detail->isImage())
                        <img src="{{ $detail->url() }}" alt="{{ $detail->alt_text ?? $detail->title }}" class="max-h-64 w-full object-contain">
                    @elseif ($detail->kind === 'video')
                        <video src="{{ $detail->url() }}" controls preload="metadata" class="max-h-64 w-full"></video>
                    @else
                        <a href="{{ $detail->url() }}" target="_blank" rel="noopener" class="flex items-center gap-2 px-4 py-6 text-[13px] font-medium text-brand-text hover:underline">
                            <x-ui.icon name="document" class="size-5" /> Open document
                        </a>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    <x-ui.pill :tone="$detail->usage_permission->tone()">{{ $detail->usage_permission->label() }}</x-ui.pill>
                    @if ($detail->archived_at)<x-ui.pill>Archived {{ $detail->archived_at->format('j M Y') }}</x-ui.pill>@endif
                    <span class="text-xs text-ink-subtle">Uploaded by {{ $detail->uploader?->name ?? 'unknown' }}, {{ $detail->created_at->format('j M Y') }}@if ($detail->width) · {{ $detail->width }}×{{ $detail->height }}@endif</span>
                </div>

                @if ($detail->isRevoked())
                    <p class="rounded-md border border-danger/30 bg-danger-soft/50 px-3 py-2 text-xs text-ink">Permission revoked: this file stays on packages that already show it, but it can no longer be chosen for a package.</p>
                @endif

                <section class="grid gap-1.5">
                    <h3 class="text-xs font-semibold tracking-wide text-ink-subtle uppercase">Usage</h3>
                    @if ($usage['packages'] === 0)
                        <p class="text-[13px] text-ink-muted">Not used on any package yet.</p>
                    @else
                        <p class="text-[13px] text-ink">Used in {{ $usage['packages'] }} {{ str('package')->plural($usage['packages']) }}{{ $usage['published'] ? ', '.$usage['published'].' published' : '' }}.</p>
                        <ul class="grid gap-1">
                            @foreach ($detail->packages as $package)
                                <li class="flex items-center justify-between gap-2 text-[13px]">
                                    @if (\Illuminate\Support\Facades\Route::has('travel.packages.show'))
                                        <a href="{{ route('travel.packages.show', $package->id) }}" wire:navigate class="truncate font-medium text-brand-text hover:underline">{{ $package->name }}</a>
                                    @else
                                        <span class="truncate text-ink">{{ $package->name }}</span>
                                    @endif
                                    <x-ui.pill :tone="$package->status->tone()">{{ $package->status->label() }}</x-ui.pill>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <form wire:submit="saveDetail" id="media-detail-form" class="grid gap-3">
                    <fieldset @disabled(! $canEditDetail) class="grid gap-3 disabled:opacity-80">
                        <x-ui.input label="Title" wire:model="form.title" id="detail-title" />
                        <x-ui.input label="Alt text" wire:model="form.alt_text" id="detail-alt" hint="Describes the image for screen readers." />
                        <div class="grid gap-1">
                            <label for="detail-description" class="text-xs font-medium text-ink-muted">Description</label>
                            <textarea id="detail-description" wire:model="form.description" rows="2" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none"></textarea>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.select label="Provider" wire:model="form.travel_provider_id" id="detail-provider">
                                <option value="">No provider</option>
                                @foreach ($providers as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                            </x-ui.select>
                            <x-ui.input label="Destination" wire:model="form.destination" list="media-destinations" id="detail-destination" />
                            <x-ui.select label="Category" wire:model="form.category" id="detail-category">
                                @foreach ($categories as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </x-ui.select>
                            <x-ui.input label="Tags" wire:model="form.tags" id="detail-tags" />
                            <x-ui.input label="Source" wire:model="form.source" id="detail-source" />
                            <x-ui.input label="Copyright / owner" wire:model="form.copyright_owner" id="detail-owner" />
                        </div>
                        <x-ui.select label="Usage permission" wire:model="form.usage_permission" id="detail-permission" :disabled="! $canChangePermission" :hint="$canChangePermission ? null : 'Only a Sales Admin can change usage permission.'">
                            @foreach ($permissions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.input label="Usage notes" wire:model="form.usage_notes" id="detail-notes" />
                    </fieldset>
                </form>
            </div>
        @endif
        <x-slot:footer>
            @if ($detail && $canEditDetail)
                @if ($detail->archived_at)
                    <x-ui.button variant="secondary" wire:click="restore({{ $detail->id }})">Restore</x-ui.button>
                @else
                    <x-ui.button variant="secondary" icon="register" wire:click="archiveOne({{ $detail->id }})">Archive</x-ui.button>
                @endif
                @if ($canDeleteDetail)
                    <x-ui.button variant="danger-ghost" wire:click="confirmDelete({{ $detail->id }})">Delete</x-ui.button>
                @endif
                <x-ui.button type="submit" form="media-detail-form" class="ml-auto">Save</x-ui.button>
            @else
                <x-ui.button variant="secondary" x-on:click="open = false">Close</x-ui.button>
            @endif
        </x-slot:footer>
    </x-ui.slide-over>

    {{-- Archive --}}
    <x-ui.modal wire:model="showArchive" title="Archive {{ count($archiveIds) === 1 ? 'this file' : count($archiveIds).' files' }}?" description="Archived files are hidden from the gallery and can't be chosen for packages. Packages that already show them keep them.">
        @if ($archiveNeedsConfirmation)
            <div class="grid gap-3">
                @if ($isManager)
                    <p class="rounded-md border border-warning/40 bg-warning-soft/50 px-3 py-2 text-[13px] text-ink">At least one file is shown on a <strong>published</strong> package. Type <strong>{{ $confirmationPhrase }}</strong> to archive it.</p>
                    <x-ui.input label="Confirmation" wire:model="archiveConfirmation" id="archive-confirmation" :placeholder="$confirmationPhrase" />
                @else
                    <p class="rounded-md border border-danger/30 bg-danger-soft/50 px-3 py-2 text-[13px] text-ink">At least one file is shown on a published package. Only a Sales Admin can archive it; the others will be archived.</p>
                @endif
            </div>
        @endif
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button wire:click="archive">Archive</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Delete --}}
    <x-ui.modal wire:model="showDelete" title="Delete this file?" description="It isn't used on any package. The file is removed permanently.">
        @if ($deleting)
            <p class="text-[13px] text-ink">{{ $deleting->title }} <span class="text-ink-subtle">({{ $deleting->original_name }})</span></p>
        @endif
        @error('delete')<p class="mt-2 text-xs text-danger">{{ $message }}</p>@enderror
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="delete">Delete permanently</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
