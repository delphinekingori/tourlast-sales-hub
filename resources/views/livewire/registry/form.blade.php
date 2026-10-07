@php
    $isNew = $engagement === null;
    $duplicates = $this->duplicates;
    $hasRegistryDuplicate = $duplicates->contains('kind', 'registry');
    $textarea = 'w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink placeholder:text-ink-subtle focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
@endphp

<div class="grid gap-5">
    <div class="flex flex-wrap items-center gap-2 text-[13px]">
        <a href="{{ $isNew ? route('registry.index') : route('registry.show', $engagement) }}" wire:navigate class="font-medium text-brand-text hover:underline">← {{ $isNew ? 'Property Engagement Registry' : $engagement->name }}</a>
    </div>

    <x-ui.page-header
        :title="$isNew ? 'Add property' : 'Edit property'"
        :description="$isNew ? 'Record a property or business Tourlast has engaged. The Hub checks the registry for the same property as you type.' : 'Every change is recorded on the property\'s timeline with the old and new value.'"
    />

    <form wire:submit="save" class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="grid min-w-0 gap-4">
            <x-ui.card title="Property information">
                <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    <div class="md:col-span-2 2xl:col-span-1">
                        <x-ui.input label="Property / business name *" wire:model.live.debounce.400ms="name" id="pe-name" placeholder="e.g. PrideInn Paradise Beach Resort" autocomplete="off" />
                    </div>
                    <x-ui.select label="Property type *" wire:model.live="property_type" id="pe-type">
                        @foreach (config('hub.property_types') as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                    @if ($this->isAccommodation())
                        <x-ui.select label="Star rating" wire:model="star_rating" id="pe-stars">
                            <option value="">Not recorded</option>
                            @foreach (config('hub.star_ratings') as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.input label="Number of rooms / units" type="number" min="0" wire:model="rooms" id="pe-rooms" />
                    @endif
                    <x-ui.input label="Estimated capacity" type="number" min="0" wire:model="capacity" id="pe-capacity" hint="Guests, covers or seats" />
                    <x-ui.input label="Tourlast property ID" wire:model="tourlast_property_id" id="pe-tlid" placeholder="If already on tourlast.com" />
                    <x-ui.input label="Website" wire:model.live.debounce.500ms="website" id="pe-website" placeholder="www.example.com" />
                    <x-ui.input label="Trading name" wire:model.live.debounce.500ms="trading_name" id="pe-trading" />
                    <x-ui.input label="Business registration name" wire:model="registration_name" id="pe-regname" />
                    <x-ui.input label="Business registration number" wire:model.live.debounce.500ms="registration_number" id="pe-regno" />
                    <x-ui.input label="KRA PIN" wire:model.live.debounce.500ms="kra_pin" id="pe-kra" placeholder="e.g. P051234567X" />
                </div>
            </x-ui.card>

            <x-ui.card title="Location">
                <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    <x-ui.input label="Country *" wire:model="country" id="pe-country" />
                    <x-ui.input label="County / region *" wire:model="region" id="pe-region" list="pe-regions" placeholder="e.g. Mombasa" />
                    <x-ui.input label="City / town *" wire:model.live.debounce.400ms="city" id="pe-city" list="pe-cities" placeholder="e.g. Mombasa" />
                    <x-ui.input label="Area / neighbourhood" wire:model="area" id="pe-area" placeholder="e.g. Shanzu" />
                    <div class="md:col-span-2"><x-ui.input label="Physical address" wire:model="address" id="pe-address" /></div>
                    <x-ui.input label="Latitude" wire:model="latitude" id="pe-lat" inputmode="decimal" placeholder="-3.9942" />
                    <x-ui.input label="Longitude" wire:model="longitude" id="pe-lng" inputmode="decimal" placeholder="39.7451" />
                </div>
                <datalist id="pe-regions">@foreach ($this->knownRegions as $option)<option value="{{ $option }}"></option>@endforeach</datalist>
                <datalist id="pe-cities">@foreach ($this->knownCities as $option)<option value="{{ $option }}"></option>@endforeach</datalist>
            </x-ui.card>

            @if ($isNew)
                <x-ui.card title="Primary contact" description="More contacts can be added on the property's profile.">
                    <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                        <x-ui.input label="Contact person name *" wire:model="contact_name" id="pe-cname" />
                        <x-ui.select label="Job title / position *" wire:model="contact_title" id="pe-ctitle">
                            <option value="">Choose…</option>
                            @foreach (config('hub.contact_titles') as $title)
                                <option value="{{ $title }}">{{ $title }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.input label="Phone number *" type="tel" wire:model.live.debounce.500ms="contact_phone" id="pe-cphone" placeholder="+254 7xx xxx xxx" />
                        <x-ui.input label="WhatsApp number" type="tel" wire:model.live.debounce.500ms="contact_whatsapp" id="pe-cwhatsapp" />
                        <x-ui.input label="Email address" type="email" wire:model.live.debounce.500ms="contact_email" id="pe-cemail" />
                    </div>
                </x-ui.card>
            @endif

            <x-ui.card title="Engagement details">
                <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    <x-ui.select label="Primary sales representative *" wire:model.live="sales_rep_id" id="pe-rep" :hint="$isNew ? null : 'Changing this is a transfer: the previous representative stays in the history.'">
                        <option value="">Choose…</option>
                        @foreach ($salespeople as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </x-ui.select>
                    @if ($this->repChanged())
                        <div class="grid gap-3 rounded-md border border-brand/25 bg-brand-soft/40 p-3 md:col-span-2 2xl:col-span-3 md:grid-cols-2">
                            <p class="text-xs font-semibold text-ink md:col-span-2">Transfer ownership from {{ $engagement->salesRep?->name ?? 'Unassigned' }}</p>
                            <x-ui.select label="Reason *" wire:model="rep_reason" id="pe-rep-reason">
                                <option value="">Choose…</option>
                                @foreach (\App\Models\LeadTransfer::Reasons as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.input label="Notes" wire:model="rep_notes" id="pe-rep-notes" placeholder="Optional" />
                        </div>
                    @endif
                    <x-ui.input label="Date first engaged *" type="date" wire:model="first_engaged_on" id="pe-first" max="{{ now()->toDateString() }}" />
                    <x-ui.select label="Engagement source" wire:model="source" id="pe-source">
                        <option value="">Not recorded</option>
                        @foreach (\App\Enums\EngagementSource::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Current stage *" wire:model="stage" id="pe-stage" hint="Where it is in the acquisition process.">
                        @foreach (\App\Enums\EngagementStage::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->order() }}. {{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Engagement status *" wire:model.live="status" id="pe-status" hint="Its current condition.">
                        @foreach (\App\Enums\EngagementStatus::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <div class="hidden 2xl:block"></div>
                    @if ($this->closingStatus())
                        <div class="grid gap-2 rounded-md border border-warning/40 bg-warning-soft/40 p-3 md:col-span-2 2xl:col-span-3">
                            <p class="text-xs font-semibold text-ink">{{ $this->outcomeRequired() ? 'Why did they say no? (required for Lost and Rejected)' : 'Why is it '.$status.'? (optional)' }}</p>
                            <x-outcome-fields model="outcome" notes="outcome_notes" :objection="$outcome['objection'] ?: null" :required="$this->outcomeRequired()" id="pe-outcome" class="md:grid-cols-2" />
                        </div>
                    @endif
                    <div class="grid gap-1 md:col-span-2 2xl:col-span-3">
                        <label for="pe-summary" class="text-xs font-medium text-ink-muted">Engagement summary</label>
                        <textarea id="pe-summary" wire:model="summary" rows="3" class="{{ $textarea }}" placeholder="What Tourlast knows about this property and the relationship so far."></textarea>
                        @error('summary')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div class="md:col-span-1 2xl:col-span-2"><x-ui.input label="Next action" wire:model="next_action" id="pe-next" placeholder="e.g. Send commercial proposal" /></div>
                    <x-ui.input label="Next action date" type="date" wire:model="next_action_on" id="pe-next-on" />
                </div>
            </x-ui.card>
        </div>

        <aside class="grid gap-4 xl:sticky xl:top-20">
            @if ($duplicates->isEmpty())
                <section class="rounded-xl border border-line bg-surface shadow-card" aria-live="polite">
                    <header class="flex items-center gap-2 border-b border-line px-4 py-2.5">
                        <x-ui.icon name="search" class="size-4 text-ink-subtle" />
                        <h2 class="text-sm font-semibold text-ink">Duplicate check</h2>
                        <span wire:loading.delay wire:target="name,trading_name,city,contact_phone,contact_whatsapp,contact_email,website,registration_number,kra_pin" class="ml-auto text-xs text-ink-subtle">Checking…</span>
                    </header>
                    <p class="px-4 py-3 text-[13px] text-ink-muted">
                        @if (trim($name) === '')
                            Start typing the name. The registry, every salesperson's leads and tourlast.com signups are checked by name, trading name, town, phone, email, website, registration number and KRA PIN.
                        @else
                            No matching property found.
                        @endif
                    </p>
                </section>
            @else
                <x-duplicate-matches :matches="$duplicates" class="bg-surface shadow-card"
                    :confirm="$isNew && $hasRegistryDuplicate ? 'confirmDifferent' : null"
                    confirm-label="I've checked: this is a different property and should get its own record." />
                @if (! $hasRegistryDuplicate)
                    <p class="-mt-2 px-1 text-xs text-ink-subtle">Matching leads or signups are shown for information. Once saved, link them from the property's profile.</p>
                @endif
            @endif

            <div class="flex flex-wrap justify-end gap-2 rounded-xl border border-line bg-surface p-3 shadow-card">
                @if ($errors->any())
                    <p class="mr-auto self-center text-xs text-danger">Please fix the highlighted fields.</p>
                @endif
                <x-ui.button variant="secondary" :href="$isNew ? route('registry.index') : route('registry.show', $engagement)" wire:navigate>Cancel</x-ui.button>
                <x-ui.button type="submit" :disabled="$isNew && $hasRegistryDuplicate && ! $confirmDifferent">{{ $isNew ? 'Add to registry' : 'Save changes' }}</x-ui.button>
            </div>
        </aside>
    </form>
</div>
