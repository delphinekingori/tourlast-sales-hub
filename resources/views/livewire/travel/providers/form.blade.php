@php
    $textarea = 'w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink placeholder:text-ink-subtle focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
    $duplicates = $this->duplicates;
@endphp

<div class="grid gap-5">
    <a href="{{ $isEdit ? route('travel.providers.show', $providerId) : route('travel.providers.index') }}" wire:navigate class="text-[13px] font-medium text-brand-text hover:underline">← {{ $isEdit ? 'Back to provider' : 'Providers' }}</a>

    <x-ui.page-header :title="$isEdit ? 'Edit provider' : 'Add provider'" :description="$seesRegistry ? 'Tour, safari and experience partners. The Hub checks existing providers and the Property Engagement Registry as you type.' : 'Tour, safari and experience partners. The Hub checks existing providers as you type.'" />

    <form wire:submit="save" class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="grid gap-5">
            <x-ui.card title="Business">
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.input label="Provider name *" wire:model.live.debounce.500ms="form.name" />
                    <x-ui.select label="Provider type *" wire:model="form.provider_type">
                        @foreach (\App\Enums\Travel\TravelProviderType::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Business (registered) name" wire:model="form.business_name" />
                    <x-ui.input label="Trading name" wire:model.live.debounce.500ms="form.trading_name" />
                    <x-ui.input label="Registration number" wire:model.live.debounce.500ms="form.registration_number" />
                    <x-ui.input label="KRA PIN" wire:model.live.debounce.500ms="form.kra_pin" hint="Kenyan businesses, e.g. P051234567X" />
                </div>
            </x-ui.card>

            <x-ui.card title="Location">
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-ui.input label="Country *" wire:model="form.country" />
                    <x-ui.input label="County / region" wire:model="form.region" />
                    <x-ui.input label="City / town" wire:model.live.debounce.500ms="form.city" />
                    <div class="sm:col-span-3"><x-ui.input label="Physical address" wire:model="form.address" /></div>
                </div>
            </x-ui.card>

            <x-ui.card title="Contact">
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.input label="Email" type="email" wire:model.live.debounce.500ms="form.email" />
                    <x-ui.input label="Phone" wire:model.live.debounce.500ms="form.phone" />
                    <x-ui.input label="WhatsApp" wire:model="form.whatsapp" />
                    <x-ui.input label="Website" wire:model.live.debounce.500ms="form.website" />
                    <x-ui.input label="Primary contact" wire:model="form.primary_contact_name" />
                    <x-ui.input label="Primary contact phone" wire:model.live.debounce.500ms="form.primary_contact_phone" />
                    <x-ui.input label="Primary contact email" type="email" wire:model.live.debounce.500ms="form.primary_contact_email" />
                    <div></div>
                    <x-ui.input label="Decision maker" wire:model="form.decision_maker_name" />
                    <x-ui.input label="Decision maker phone" wire:model="form.decision_maker_phone" />
                </div>
            </x-ui.card>

            <x-ui.card title="About">
                <div class="grid gap-3">
                    <div class="grid gap-1">
                        <label for="provider-description" class="text-xs font-medium text-ink-muted">Description</label>
                        <textarea id="provider-description" rows="4" wire:model="form.description" class="{{ $textarea }}" placeholder="What they offer, where they operate, fleet size..."></textarea>
                    </div>
                    <div class="grid gap-1">
                        <label for="provider-notes" class="text-xs font-medium text-ink-muted">Internal notes</label>
                        <textarea id="provider-notes" rows="3" wire:model="form.notes" class="{{ $textarea }}"></textarea>
                    </div>
                </div>
            </x-ui.card>
        </div>

        <div class="grid gap-5 xl:sticky xl:top-4">
            <x-ui.card title="Status">
                <div class="grid gap-3">
                    <x-ui.select label="Status *" wire:model="form.status">
                        @foreach (\App\Enums\Travel\TravelProviderStatus::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    @if ($canManageAll)
                        <x-ui.select label="Salesperson" wire:model="form.owner_id">
                            @foreach ($owners as $person)
                                <option value="{{ $person->id }}">{{ $person->name }}</option>
                            @endforeach
                        </x-ui.select>
                    @else
                        <p class="text-xs text-ink-subtle">Providers you add are assigned to you.</p>
                    @endif

                    @if ($seesRegistry)
                    <div class="grid gap-1 rounded-md border border-line bg-surface-muted/50 p-2.5 text-[13px]">
                        <span class="text-xs font-medium text-ink-muted">Property Engagement Registry</span>
                        @if ($linked)
                            <span class="flex items-center justify-between gap-2">
                                <span class="truncate text-ink">Linked to {{ $linked->name }}</span>
                                <button type="button" wire:click="unlinkRegistry" class="text-xs font-medium text-danger hover:underline">Unlink</button>
                            </span>
                        @else
                            <span class="text-ink-subtle">Not linked. Matching Registry records appear below.</span>
                        @endif
                    </div>
                    @endif
                </div>
            </x-ui.card>

            @if ($duplicates->isNotEmpty())
                <section class="grid gap-2 rounded-lg border border-warning/40 bg-warning-soft/40 p-3" aria-live="polite">
                    <p class="flex items-center gap-2 text-[13px] font-semibold text-ink">
                        <x-ui.icon name="alert" class="size-4 text-warning" />
                        {{ $duplicates->count() === 1 ? 'Possible duplicate found' : $duplicates->count().' possible duplicates found' }}
                    </p>
                    @foreach ($duplicates as $match)
                        <div wire:key="dup-{{ $match['key'] }}" class="grid gap-1 rounded-md border border-line bg-surface p-2.5">
                            <div class="flex items-start justify-between gap-2">
                                <span class="grid min-w-0 leading-tight">
                                    <span class="truncate text-[13px] font-semibold text-ink">{{ $match['name'] }}</span>
                                    <span class="truncate text-xs text-ink-subtle">{{ $match['status'] }}{{ $match['location'] ? ' · '.$match['location'] : '' }}{{ $match['owner'] ? ' · '.$match['owner'] : '' }}</span>
                                </span>
                                @if ($match['strong'])
                                    <x-ui.pill tone="danger" :dot="false">Strong match</x-ui.pill>
                                @endif
                            </div>
                            <p class="text-xs text-ink-muted">{{ implode(' · ', $match['reasons']) }}</p>
                            <div class="flex flex-wrap gap-2">
                                @if ($match['url'])
                                    <a href="{{ $match['url'] }}" target="_blank" class="text-xs font-medium text-brand-text hover:underline">View existing</a>
                                @endif
                                @if ($match['kind'] === 'registry')
                                    @if ($match['linked'])
                                        <span class="text-xs font-medium text-success">Linked</span>
                                    @else
                                        <button type="button" wire:click="linkRegistry({{ $match['id'] }})" class="text-xs font-medium text-brand-text hover:underline">Link to this Registry record</button>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endforeach

                    @if ($checked && $duplicates->where('kind', 'provider')->isNotEmpty())
                        @if ($strongMatch && ! $canManageAll)
                            <p class="text-xs text-danger">A provider with the same details already exists. Open it instead, or ask a Travel manager.</p>
                        @else
                            <x-ui.button size="sm" variant="secondary" wire:click="continueAnyway">Continue anyway: this is a different business</x-ui.button>
                        @endif
                    @endif
                </section>
            @endif

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" :href="$isEdit ? route('travel.providers.show', $providerId) : route('travel.providers.index')" wire:navigate>Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $isEdit ? 'Save changes' : 'Add provider' }}</x-ui.button>
            </div>
        </div>
    </form>
</div>
