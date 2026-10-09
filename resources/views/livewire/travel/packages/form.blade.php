@php
    $ready = collect($readiness)->every(fn ($item) => $item['ok']);
    $readyCount = collect($readiness)->where('ok', true)->count();
    $textarea = 'w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink shadow-xs focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
    $listLabels = ['highlights' => 'Highlights', 'inclusions' => 'Inclusions', 'exclusions' => 'Exclusions', 'what_to_bring' => 'What to bring'];
    $exactDuplicate = $duplicates->contains('exact', true);
    $managesAll = \App\Support\Travel\TravelAccess::managesAll(auth()->user());
@endphp

<div class="grid gap-5">
    <x-ui.page-header :title="$package ? 'Edit '.$package->name : 'New package'" :description="$package ? $package->reference.' · changes to prices, itinerary, provider, contract, capacity, policies or inclusions on an approved package go back for approval.' : 'Packages start as drafts. Submit for approval when the readiness checklist is complete.'">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="$package ? route('travel.packages.show', $package) : route('travel.packages.index')" wire:navigate>Cancel</x-ui.button>
            <x-ui.button variant="secondary" wire:click="save" wire:loading.attr="disabled" wire:target="save,saveAndSubmit">Save draft</x-ui.button>
            <x-ui.button wire:click="saveAndSubmit" wire:loading.attr="disabled" wire:target="save,saveAndSubmit">Save &amp; submit for approval</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($materialPreview !== [])
        <div class="flex flex-wrap items-start gap-2 rounded-lg border border-danger/40 bg-danger-soft/40 px-4 py-2.5 text-[13px] text-ink">
            <x-ui.icon name="alert" class="size-4 text-danger" />
            <div class="grid gap-0.5">
                <p class="font-semibold">Approval required</p>
                <p>You changed {{ collect(array_keys($materialPreview))->map(fn ($field) => \App\Support\Travel\PackageContent::label($field))->implode(', ') }}. Saving creates a new version that needs Sales Admin and Super Admin approval. Customers keep seeing the approved version until then.</p>
            </div>
        </div>
    @endif

    @if ($duplicates->isNotEmpty())
        <div class="grid gap-1.5 rounded-lg border border-warning/40 bg-warning-soft/50 px-4 py-2.5 text-[13px]">
            <p class="font-semibold text-ink">Possible duplicate package found</p>
            @foreach ($duplicates->take(4) as $match)
                <p><a href="{{ route('travel.packages.show', $match['package']) }}" target="_blank" class="font-medium text-brand-text hover:underline">{{ $match['package']->name }}</a> <span class="text-ink-muted">({{ $match['package']->reference }} · {{ $match['package']->provider?->name }} · {{ $match['reason'] }} · {{ $match['package']->owner?->name }})</span></p>
            @endforeach
            @if (! $exactDuplicate || $managesAll)
                <label class="mt-1 flex items-center gap-2 text-ink">
                    <input type="checkbox" wire:model="acceptDuplicate" class="rounded border-line-strong">
                    Continue anyway: this is a different package
                </label>
            @else
                <p class="text-ink-muted">A package with this exact name already exists for this provider. Open it instead, or ask a Sales Admin.</p>
            @endif
        </div>
    @endif
    @if ($errors->any() && ! $errors->has('duplicate') && ! $errors->has('package'))
        <div class="grid gap-1 rounded-lg border border-danger/40 bg-danger-soft/50 px-4 py-2.5 text-[13px] text-ink" role="alert">
            <p class="flex items-center gap-2 font-semibold"><x-ui.icon name="alert" class="size-4 text-danger" /> Not saved yet. Please fix {{ $errors->count() === 1 ? 'this' : 'these' }}:</p>
            <ul class="ml-6 list-disc text-ink-muted">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @error('duplicate') <p class="rounded-md bg-danger-soft px-3 py-2 text-[13px] text-danger">{{ $message }}</p> @enderror
    @error('package') <p class="rounded-md bg-danger-soft px-3 py-2 text-[13px] text-danger">{{ $message }}</p> @enderror

    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_300px]">
        <div class="grid min-w-0 gap-4">
            <div class="flex flex-wrap gap-1 border-b border-line" role="tablist">
                @foreach (\App\Livewire\Travel\Packages\Form::Tabs as $key => $label)
                    <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        @class(['-mb-px border-b-2 px-3 py-2 text-[13px] font-medium', 'border-brand text-ink' => $tab === $key, 'border-transparent text-ink-muted hover:text-ink' => $tab !== $key])>
                        {{ $label }}
                        @if ($key === 'media' && count($media))<span class="ml-1 text-xs text-ink-subtle">{{ count($media) }}</span>@endif
                        @if ($key === 'itinerary')<span class="ml-1 text-xs text-ink-subtle">{{ count($itinerary) }}</span>@endif
                    </button>
                @endforeach
            </div>

            @if ($tab === 'basics')
                <x-ui.card title="Package basics">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="md:col-span-2"><x-ui.input label="Package name *" wire:model.live.debounce.500ms="form.name" placeholder="Masai Mara 3-Day Safari" /></div>
                        <div class="grid gap-1 md:col-span-2">
                            <label for="short-description" class="text-xs font-medium text-ink-muted">Short description *</label>
                            <input id="short-description" wire:model.blur="form.short_description" maxlength="300" class="h-9 w-full rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink" />
                            @error('form.short_description') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid gap-1 md:col-span-2">
                            <label for="description" class="text-xs font-medium text-ink-muted">Full description *</label>
                            <textarea id="description" wire:model.blur="form.description" rows="4" class="{{ $textarea }}"></textarea>
                            @error('form.description') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                        <x-ui.select label="Package type *" wire:model="form.package_type">
                            @foreach (\App\Models\Package::Types as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.select label="Provider *" wire:model.live="form.travel_provider_id">
                            <option value="">Choose a provider</option>
                            @foreach ($providers as $provider)
                                <option value="{{ $provider->id }}">{{ $provider->name }}{{ $provider->isActive() ? '' : ' ('.$provider->status->label().')' }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.select label="Contract *" wire:model.live="form.provider_contract_id" :hint="$package && ! $package->live_version_id && str_contains((string) $package->workingVersion?->change_note, 'Confirm') ? 'Copied package: confirm this is the right contract.' : null">
                            <option value="">No contract</option>
                            @foreach ($contracts as $contract)
                                <option value="{{ $contract->id }}">{{ $contract->contract_number }} · {{ $contract->contract_type }} · {{ $contract->effectiveStatus()->label() }}{{ $contract->ends_on ? ' · ends '.$contract->ends_on->format('j M Y') : '' }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.input label="Destination *" wire:model.live.debounce.500ms="form.destination" placeholder="Masai Mara" />
                        <x-ui.input label="Country *" wire:model="form.country" />
                        <x-ui.input label="County / region" wire:model="form.region" />
                        <x-ui.input label="Starting location" wire:model="form.start_location" />
                        <x-ui.input label="Ending location" wire:model="form.end_location" />
                        <x-ui.input label="Duration" wire:model="form.duration_label" placeholder="3 days, 2 nights" />
                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.input label="Days *" type="number" min="1" wire:model="form.days" />
                            <x-ui.input label="Nights" type="number" min="0" wire:model="form.nights" />
                        </div>
                        <x-ui.select label="Difficulty" wire:model="form.difficulty">
                            <option value="">Not relevant</option>
                            @foreach (\App\Support\Travel\PackageContent::Difficulties as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                        <div class="grid grid-cols-3 gap-3">
                            <x-ui.input label="Min travelers" type="number" min="1" wire:model="form.min_travelers" />
                            <x-ui.input label="Max travelers" type="number" min="1" wire:model="form.max_travelers" />
                            <x-ui.input label="Capacity *" type="number" min="1" wire:model="form.default_capacity" hint="Slots per departure" />
                        </div>
                        <div class="grid grid-cols-[100px_1fr] gap-3">
                            <x-ui.input label="Minimum age" type="number" min="0" wire:model="form.min_age" />
                            <x-ui.input label="Age notes" wire:model="form.age_notes" placeholder="Children under 5 travel free" />
                        </div>
                    </div>
                </x-ui.card>
            @elseif ($tab === 'content')
                <x-ui.card title="Package content">
                    <div class="grid gap-4">
                        <div class="grid gap-1">
                            <label for="overview" class="text-xs font-medium text-ink-muted">Overview</label>
                            <textarea id="overview" wire:model.blur="form.overview" rows="3" class="{{ $textarea }}"></textarea>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            @foreach ($listLabels as $field => $label)
                                <div class="grid content-start gap-1.5">
                                    <p class="text-xs font-medium text-ink-muted">{{ $label }}{{ in_array($field, ['inclusions', 'exclusions'], true) ? ' *' : '' }}</p>
                                    @foreach ($form[$field] as $index => $item)
                                        <div wire:key="{{ $field }}-{{ $index }}" class="flex items-center gap-1.5">
                                            <input wire:model.blur="form.{{ $field }}.{{ $index }}" aria-label="{{ $label }} {{ $index + 1 }}" class="h-8 w-full rounded-md border border-line-strong bg-surface px-2.5 text-[13px] text-ink" />
                                            <button type="button" wire:click="removeListItem('{{ $field }}', {{ $index }})" class="rounded p-1 text-ink-subtle hover:text-danger" aria-label="Remove"><x-ui.icon name="x" class="size-3.5" /></button>
                                        </div>
                                    @endforeach
                                    <button type="button" wire:click="addListItem('{{ $field }}')" class="justify-self-start text-xs font-medium text-brand-text hover:underline">+ Add</button>
                                </div>
                            @endforeach
                        </div>
                        @foreach ([
                            'requirements' => ['Requirements', 2, false],
                            'terms' => ['Terms & conditions', 3, false],
                            'cancellation_policy' => ['Cancellation policy *', 3, true],
                            'refund_policy' => ['Refund policy *', 2, true],
                            'pickup_info' => ['Pickup information', 2, false],
                            'dropoff_info' => ['Drop-off information', 2, false],
                        ] as $field => [$label, $rows, $required])
                            <div class="grid gap-1">
                                <label for="f-{{ $field }}" class="text-xs font-medium text-ink-muted">{{ $label }}</label>
                                <textarea id="f-{{ $field }}" wire:model.blur="form.{{ $field }}" rows="{{ $rows }}" class="{{ $textarea }}"></textarea>
                                @error('form.'.$field) <p class="text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                        <x-ui.input label="Meeting point" wire:model="form.meeting_point" />
                    </div>
                </x-ui.card>
            @elseif ($tab === 'itinerary')
                <x-ui.card title="Day-by-day itinerary" description="Changing the itinerary of an approved package needs re-approval.">
                    <x-slot:actions>
                        <x-ui.button size="sm" variant="secondary" icon="plus" wire:click="addDay">Add day</x-ui.button>
                    </x-slot:actions>
                    <ol class="grid gap-3">
                        @foreach ($itinerary as $index => $day)
                            <li wire:key="day-{{ $index }}" class="grid gap-3 rounded-lg border border-line p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-[13px] font-bold text-ink">Day {{ $index + 1 }}</p>
                                    <div class="flex items-center gap-1">
                                        <button type="button" wire:click="moveDay({{ $index }}, -1)" @disabled($index === 0) class="rounded px-1.5 text-ink-subtle hover:text-ink disabled:opacity-30" aria-label="Move up">↑</button>
                                        <button type="button" wire:click="moveDay({{ $index }}, 1)" @disabled($index === count($itinerary) - 1) class="rounded px-1.5 text-ink-subtle hover:text-ink disabled:opacity-30" aria-label="Move down">↓</button>
                                        <x-ui.button size="sm" variant="danger-ghost" wire:click="removeDay({{ $index }})">Remove</x-ui.button>
                                    </div>
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <div class="md:col-span-2"><x-ui.input label="Title *" wire:model.blur="itinerary.{{ $index }}.title" id="day-{{ $index }}-title" /></div>
                                    <div class="grid gap-1 md:col-span-2">
                                        <label for="day-{{ $index }}-desc" class="text-xs font-medium text-ink-muted">Description</label>
                                        <textarea id="day-{{ $index }}-desc" wire:model.blur="itinerary.{{ $index }}.description" rows="2" class="{{ $textarea }}"></textarea>
                                    </div>
                                    <x-ui.input label="Activities" wire:model.blur="itinerary.{{ $index }}.activities" id="day-{{ $index }}-act" />
                                    <x-ui.input label="Accommodation" wire:model.blur="itinerary.{{ $index }}.accommodation" id="day-{{ $index }}-acc" />
                                    <x-ui.input label="Transport" wire:model.blur="itinerary.{{ $index }}.transport" id="day-{{ $index }}-trn" />
                                    <x-ui.input label="Notes" wire:model.blur="itinerary.{{ $index }}.notes" id="day-{{ $index }}-notes" />
                                    <fieldset class="flex flex-wrap items-center gap-3 md:col-span-2">
                                        <legend class="mb-1 text-xs font-medium text-ink-muted">Meals</legend>
                                        @foreach (\App\Models\PackageItineraryDay::Meals as $meal => $mealLabel)
                                            <label class="flex items-center gap-1.5 text-[13px] text-ink">
                                                <input type="checkbox" value="{{ $meal }}" wire:model="itinerary.{{ $index }}.meals" class="rounded border-line-strong"> {{ $mealLabel }}
                                            </label>
                                        @endforeach
                                    </fieldset>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                    @if ($itinerary === [])
                        <x-ui.empty-state icon="calendar" title="No days yet" description="Add the first day of the trip." />
                    @endif
                </x-ui.card>
            @elseif ($tab === 'pricing')
                <x-ui.card title="Pricing" description="Changing selling prices or the discount on an approved package needs re-approval.">
                    <div class="grid gap-3 md:grid-cols-3">
                        <x-ui.input label="Currency *" wire:model="form.currency" maxlength="3" />
                        <x-ui.input label="Adult price *" type="number" step="0.01" min="0" wire:model.blur="form.adult_price" />
                        <x-ui.input label="Child price" type="number" step="0.01" min="0" wire:model="form.child_price" />
                        <x-ui.input label="Infant price" type="number" step="0.01" min="0" wire:model="form.infant_price" />
                        <x-ui.input label="Group price (per person)" type="number" step="0.01" min="0" wire:model="form.group_price" />
                        <x-ui.input label="Group from (people)" type="number" min="2" wire:model="form.group_min_size" />
                        <x-ui.input label="Single supplement" type="number" step="0.01" min="0" wire:model="form.single_supplement" />
                        <x-ui.input label="Discount (per person)" type="number" step="0.01" min="0" wire:model="form.discount_amount" />
                    </div>
                    @if ($showFinancials)
                        <div class="mt-4 grid gap-3 border-t border-line pt-4 md:grid-cols-3">
                            <p class="text-xs font-semibold tracking-wide text-ink-subtle uppercase md:col-span-3">Internal costs · visible to finance and the package owner only</p>
                            <x-ui.input label="Original provider price" type="number" step="0.01" min="0" wire:model="form.provider_price" />
                            <x-ui.input label="Net provider price" type="number" step="0.01" min="0" wire:model.blur="form.net_provider_price" />
                            <x-ui.input label="Commission" type="number" step="0.01" min="0" wire:model="form.commission_amount" />
                            @if (filled($form['adult_price']) && filled($form['net_provider_price']))
                                <p class="text-[13px] text-ink md:col-span-3">Margin per adult: <span class="tabular font-semibold">{{ $form['currency'] }} {{ number_format((float) $form['adult_price'] - (float) ($form['discount_amount'] ?: 0) - (float) $form['net_provider_price'], 2) }}</span></p>
                            @endif
                        </div>
                    @endif
                </x-ui.card>
            @elseif ($tab === 'media')
                <x-ui.card title="Media" description="Pick images from the shared Media Gallery. The first image is shown first; mark one as primary." :padding="false">
                    <x-slot:actions>
                        @if ($galleryUrl)
                            <x-ui.button size="sm" variant="ghost" :href="$galleryUrl" target="_blank">Upload in Media Gallery</x-ui.button>
                        @endif
                        <x-ui.button size="sm" variant="secondary" icon="photo" wire:click="openMediaPicker">Select from Media Gallery</x-ui.button>
                    </x-slot:actions>
                    @error('media') <p class="mx-4 mt-3 rounded-md bg-danger-soft px-3 py-2 text-[13px] text-danger">{{ $message }}</p> @enderror
                    @if ($selectedMedia->isEmpty())
                        <x-ui.empty-state icon="photo" title="No images selected" description="Packages need at least one image before they can be submitted." />
                    @else
                        <ol class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 lg:grid-cols-4">
                            @foreach ($selectedMedia as $index => $asset)
                                <li wire:key="sel-{{ $asset->id }}" class="grid gap-1.5 rounded-lg border border-line p-2">
                                    <div class="relative aspect-[4/3] overflow-hidden rounded-md bg-surface-muted">
                                        @if ($asset->isImage())
                                            <img src="{{ $asset->url() }}" alt="{{ $asset->alt_text ?? $asset->title }}" class="size-full object-cover" loading="lazy">
                                        @endif
                                        @if ($primaryMedia === $asset->id)
                                            <span class="absolute top-1.5 left-1.5"><x-ui.pill tone="brand">Primary</x-ui.pill></span>
                                        @endif
                                        @if (! $asset->isUsable())
                                            <span class="absolute right-1.5 bottom-1.5"><x-ui.pill tone="danger">Permission revoked</x-ui.pill></span>
                                        @endif
                                    </div>
                                    <p class="truncate text-xs font-medium text-ink">{{ $asset->title }}</p>
                                    <div class="flex flex-wrap items-center gap-1 text-xs">
                                        <button type="button" wire:click="moveMedia({{ $index }}, -1)" class="rounded px-1 text-ink-subtle hover:text-ink" aria-label="Move left">←</button>
                                        <button type="button" wire:click="moveMedia({{ $index }}, 1)" class="rounded px-1 text-ink-subtle hover:text-ink" aria-label="Move right">→</button>
                                        @if ($primaryMedia !== $asset->id)
                                            <button type="button" wire:click="makePrimary({{ $asset->id }})" class="text-brand-text hover:underline">Make primary</button>
                                        @endif
                                        <button type="button" wire:click="removeMedia({{ $asset->id }})" class="ml-auto text-danger hover:underline">Remove</button>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </x-ui.card>
            @else
                <x-ui.card title="Default driver & guide" description="Used for every departure unless a departure or booking names someone else.">
                    <div class="grid gap-3 md:grid-cols-2">
                        <x-ui.select label="Driver" wire:model="team.driver_id">
                            <option value="">No default driver</option>
                            @foreach ($drivers as $driver)
                                <option value="{{ $driver->id }}">{{ $driver->name }}{{ $driver->vehicle ? ' · '.$driver->vehicle : '' }}{{ $driver->vehicle_registration ? ' ('.$driver->vehicle_registration.')' : '' }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.select label="Guide" wire:model="team.guide_id">
                            <option value="">No default guide</option>
                            @foreach ($guides as $guide)
                                <option value="{{ $guide->id }}">{{ $guide->name }}{{ $guide->languages ? ' · '.implode(', ', $guide->languages) : '' }}</option>
                            @endforeach
                        </x-ui.select>
                        <label class="flex items-center gap-2 text-[13px] text-ink md:col-span-2">
                            <input type="checkbox" wire:model="team.guide_required" class="rounded border-line-strong"> A guide is required on this package
                        </label>
                    </div>
                </x-ui.card>
            @endif
        </div>

        {{-- Readiness --}}
        <aside class="grid gap-3 xl:sticky xl:top-4">
            <x-ui.card title="Package readiness" :description="$readyCount.' of '.count($readiness).' complete'">
                <ul class="grid gap-1.5 text-[13px]">
                    @foreach ($readiness as $item)
                        <li class="flex items-center gap-2">
                            @if ($item['ok'])
                                <span class="grid size-4 place-items-center rounded-full bg-success-soft text-success"><x-ui.icon name="check" class="size-3" /></span>
                                <span class="text-ink">{{ $item['label'] }}</span>
                            @else
                                <span class="grid size-4 place-items-center rounded-full bg-danger-soft text-danger"><x-ui.icon name="x" class="size-3" /></span>
                                <span class="text-ink-muted">{{ $item['label'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-ink-subtle">{{ $ready ? 'Ready to submit for approval.' : 'Submit for approval becomes available when every item is complete.' }}</p>
            </x-ui.card>
        </aside>
    </div>

    <x-ui.modal wire:model="showMediaPicker" title="Select from Media Gallery" description="Only images cleared for use are shown. Click to select or unselect." max-width="max-w-4xl">
        <div class="grid gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.search wire:model.live.debounce.300ms="mediaSearch" placeholder="Search title, destination or tag" />
                <select wire:model.live="mediaProvider" class="h-9 max-w-52 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink" aria-label="Provider">
                    <option value="">All providers</option>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="mediaDestination" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink" aria-label="Destination">
                    <option value="">All destinations</option>
                    @foreach ($mediaDestinations as $destination)
                        <option value="{{ $destination }}">{{ $destination }}</option>
                    @endforeach
                </select>
                <select wire:model.live="mediaCategory" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink" aria-label="Category">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->value }}">{{ $category->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid max-h-[60vh] grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-4 lg:grid-cols-5">
                @forelse ($pickerMedia as $asset)
                    <button type="button" wire:key="pick-{{ $asset->id }}" wire:click="toggleMedia({{ $asset->id }})"
                        @class(['grid gap-1 rounded-lg border p-1.5 text-left', 'border-brand ring-2 ring-brand-soft' => in_array($asset->id, $media, true), 'border-line hover:border-ink-subtle/40' => ! in_array($asset->id, $media, true)])>
                        <span class="block aspect-[4/3] overflow-hidden rounded bg-surface-muted">
                            @if ($asset->isImage())
                                <img src="{{ $asset->url() }}" alt="{{ $asset->alt_text ?? $asset->title }}" class="size-full object-cover" loading="lazy">
                            @endif
                        </span>
                        <span class="truncate text-xs font-medium text-ink">{{ $asset->title }}</span>
                        <span class="truncate text-[11px] text-ink-subtle">{{ $asset->provider?->name ?? 'No provider' }} · {{ $asset->category->label() }}</span>
                    </button>
                @empty
                    <div class="col-span-full"><x-ui.empty-state icon="photo" title="No images match" description="Change the filters, or upload in the Media Gallery." /></div>
                @endforelse
            </div>
        </div>
        <x-slot:footer>
            <span class="mr-auto self-center text-[13px] text-ink-muted">{{ count($media) }} selected</span>
            <x-ui.button x-on:click="open = false">Done</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
