@php
    $p = $provider;
    $kes = fn ($amount) => 'KES '.number_format((float) $amount);
    $details = array_filter([
        'Business name' => $p->business_name,
        'Trading name' => $p->trading_name,
        'Registration number' => $p->registration_number,
        'KRA PIN' => $p->kra_pin,
        'Address' => $p->address,
        'Location' => collect([$p->city, $p->region, $p->country])->filter()->implode(', '),
    ], fn ($value) => filled($value));
    $contacts = array_filter([
        'Email' => $p->email,
        'Phone' => $p->phone,
        'WhatsApp' => $p->whatsapp,
        'Website' => $p->website,
        'Primary contact' => trim(collect([$p->primary_contact_name, $p->primary_contact_phone, $p->primary_contact_email])->filter()->implode(' · ')),
        'Decision maker' => trim(collect([$p->decision_maker_name, $p->decision_maker_phone])->filter()->implode(' · ')),
    ], fn ($value) => filled($value));
@endphp

<div class="grid gap-5" x-data="{ tab: 'overview' }">
    <a href="{{ route('travel.providers.index') }}" wire:navigate class="text-[13px] font-medium text-brand-text hover:underline">← Providers</a>

    @if ($p->archived_at)
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-2.5 text-[13px] text-danger">
            <x-ui.icon name="lock" class="size-4" />
            <span>This provider is archived. Its history is kept but it is hidden from the provider list.</span>
            @if ($canManageAll)
                <x-ui.button size="sm" variant="secondary" class="ml-auto" wire:click="restore">Restore</x-ui.button>
            @endif
        </div>
    @endif

    <section class="rounded-xl border border-line bg-surface shadow-card">
        <div class="flex flex-wrap items-start justify-between gap-4 px-5 py-4">
            <div class="grid min-w-0 gap-1.5">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl leading-tight font-bold text-ink">{{ $p->name }}</h1>
                    <x-ui.pill :tone="$p->status->tone()">{{ $p->status->label() }}</x-ui.pill>
                    @unless ($canEdit)
                        <span class="inline-flex items-center gap-1 rounded border border-line px-1.5 py-px text-[10.5px] font-semibold tracking-wide text-ink-subtle uppercase"><x-ui.icon name="lock" class="size-3" /> Read only</span>
                    @endunless
                </div>
                <p class="text-sm text-ink-muted">{{ $p->provider_type->label() }} · {{ collect([$p->city, $p->region, $p->country])->filter()->implode(', ') }}</p>
                @if ($p->propertyEngagement && auth()->user()->can(\App\Enums\Permission::ViewEngagementRegistry->value))
                    <p class="text-xs text-ink-subtle">Registry record: <a href="{{ route('registry.show', $p->property_engagement_id) }}" wire:navigate class="font-medium text-brand-text hover:underline">{{ $p->propertyEngagement->name }}</a></p>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($canEdit)
                    <x-ui.button icon="plus" wire:click="openContract({{ $p->id }})">New contract</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('travel.providers.edit', $p->id)" wire:navigate>Edit</x-ui.button>
                @endif
                <x-ui.button variant="secondary" icon="alert" wire:click="openIncident(null, {{ $p->id }})">Record incident</x-ui.button>
                @if ($canManageAll && ! $p->archived_at)
                    <x-ui.button variant="danger-ghost" wire:click="archive" wire:confirm="Archive {{ $p->name }}? It will be hidden from the provider list; its history is kept.">Archive</x-ui.button>
                @endif
            </div>
        </div>

        <dl class="grid grid-cols-2 gap-px border-t border-line bg-line sm:grid-cols-3 xl:grid-cols-6">
            @foreach ([
                ['Salesperson', $p->owner?->name ?? 'Unassigned'],
                ['Active contract', $activeContract ? $activeContract->contract_number : 'None'],
                ['Contract ends', $activeContract?->ends_on ? $activeContract->ends_on->format('j M Y').' ('.$activeContract->daysUntilExpiry().' days)' : '—'],
                ['Packages', $performance['packages']],
                ['Bookings sold', number_format($performance['bookings'])],
                ['Last activity', $performance['lastActivity']?->diffForHumans() ?? '—'],
            ] as [$label, $value])
                <div class="grid gap-0.5 bg-surface px-4 py-2.5">
                    <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                    <dd class="truncate text-[13px] font-semibold text-ink">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        <nav class="flex gap-1 overflow-x-auto border-t border-line px-3" aria-label="Provider sections">
            @foreach (['overview' => 'Overview', 'contracts' => 'Contracts ('.$p->contracts->count().')', 'packages' => 'Packages ('.$p->packages->count().')', 'incidents' => 'Incidents ('.$p->incidents->count().')', 'performance' => 'Performance'] as $key => $label)
                <button type="button" x-on:click="tab = '{{ $key }}'" class="border-b-2 px-3 py-2.5 text-[13px] font-medium whitespace-nowrap" x-bind:class="tab === '{{ $key }}' ? 'border-brand text-ink' : 'border-transparent text-ink-muted hover:text-ink'">{{ $label }}</button>
            @endforeach
        </nav>
    </section>

    {{-- Overview --}}
    <div x-show="tab === 'overview'" class="grid items-start gap-4 lg:grid-cols-2">
        <x-ui.card title="Business">
            <dl class="grid gap-2 text-[13px]">
                @forelse ($details as $label => $value)
                    <div class="grid grid-cols-[10rem_1fr] gap-2"><dt class="text-ink-subtle">{{ $label }}</dt><dd class="text-ink">{{ $value }}</dd></div>
                @empty
                    <p class="text-ink-subtle">No business details yet.</p>
                @endforelse
            </dl>
            @if ($p->description)
                <p class="mt-3 border-t border-line pt-3 text-[13px] whitespace-pre-line text-ink-muted">{{ $p->description }}</p>
            @endif
        </x-ui.card>
        <x-ui.card title="Contacts">
            <dl class="grid gap-2 text-[13px]">
                @forelse ($contacts as $label => $value)
                    <div class="grid grid-cols-[10rem_1fr] gap-2"><dt class="text-ink-subtle">{{ $label }}</dt><dd class="break-words text-ink">{{ $value }}</dd></div>
                @empty
                    <p class="text-ink-subtle">No contact details yet.</p>
                @endforelse
            </dl>
            @if ($p->notes)
                <p class="mt-3 border-t border-line pt-3 text-[13px] whitespace-pre-line text-ink-muted">{{ $p->notes }}</p>
            @endif
            <p class="mt-3 border-t border-line pt-3 text-xs text-ink-subtle">Added {{ $p->created_at->format('j M Y') }}{{ $p->creator ? ' by '.$p->creator->name : '' }}</p>
        </x-ui.card>
    </div>

    {{-- Contracts --}}
    <div x-show="tab === 'contracts'" x-cloak>
        <x-ui.table-card :sticky="false">
            <table class="w-full min-w-[860px] text-sm">
                <thead class="text-left text-ink-subtle uppercase">
                    <tr>
                        <th class="text-left">Contract</th>
                        <th class="text-left">Type</th>
                        <th class="text-left">Period</th>
                        <th class="text-left">Status</th>
                        <th class="text-left">Commission</th>
                        <th class="text-right">Document</th>
                        <th class="text-right"><span class="sr-only">Action</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($p->contracts as $contract)
                        @php $state = $contract->effectiveStatus(); @endphp
                        <tr wire:key="pc-{{ $contract->id }}">
                            <td class="font-semibold text-ink"><a href="{{ route('travel.contracts.show', $contract->id) }}" wire:navigate class="hover:text-brand-text">{{ $contract->contract_number }}</a></td>
                            <td class="text-ink-muted">{{ $contract->contract_type }}</td>
                            <td class="tabular whitespace-nowrap text-ink-muted">{{ $contract->starts_on->format('j M Y') }} – {{ $contract->ends_on?->format('j M Y') ?? 'open' }}</td>
                            <td><x-ui.pill :tone="$state->tone()">{{ $state->label() }}</x-ui.pill></td>
                            <td class="whitespace-nowrap text-ink-muted">
                                {{ $contract->commission_model->label() }}
                                @if ($seesCommission)
                                    @if ($contract->commission_rate !== null) · {{ rtrim(rtrim((string) $contract->commission_rate, '0'), '.') }}% @endif
                                    @if ($contract->fixed_commission !== null) · {{ $contract->currency }} {{ number_format((float) $contract->fixed_commission) }} @endif
                                @endif
                            </td>
                            <td class="text-right text-[13px]">@include('livewire.travel.contracts.partials.document-cell', ['visible' => $seesDocuments])</td>
                            <td class="text-right"><x-ui.button size="sm" variant="secondary" :href="route('travel.contracts.show', $contract->id)" wire:navigate>Open</x-ui.button></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-ui.empty-state icon="document" title="No contracts yet" description="Create a contract before this provider's packages can be published." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.table-card>
    </div>

    {{-- Packages --}}
    <div x-show="tab === 'packages'" x-cloak>
        <x-ui.table-card :sticky="false">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="text-left text-ink-subtle uppercase">
                    <tr>
                        <th class="text-left">Package</th>
                        <th class="text-left">Destination</th>
                        <th class="text-left">Status</th>
                        <th class="text-left">Created by</th>
                        <th class="text-left">Updated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($p->packages as $package)
                        <tr wire:key="pp-{{ $package->id }}">
                            <td class="font-semibold text-ink">
                                @if ($packageRoute)
                                    <a href="{{ route('travel.packages.show', $package->id) }}" wire:navigate class="hover:text-brand-text">{{ $package->name }}</a>
                                @else
                                    {{ $package->name }}
                                @endif
                                <span class="block text-xs font-normal text-ink-subtle">{{ $package->reference }}</span>
                            </td>
                            <td class="text-ink-muted">{{ $package->destination }}</td>
                            <td><x-ui.pill :tone="$package->status->tone()">{{ $package->status->label() }}</x-ui.pill></td>
                            <td class="text-ink-muted">{{ $package->owner?->name }}</td>
                            <td class="tabular text-ink-muted">{{ $package->updated_at->format('j M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="map" title="No packages yet" description="Packages for this provider will appear here." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.table-card>
    </div>

    {{-- Incidents --}}
    <div x-show="tab === 'incidents'" x-cloak>
        <x-ui.table-card :sticky="false">
            <table class="w-full min-w-[860px] text-sm">
                <thead class="text-left text-ink-subtle uppercase">
                    <tr>
                        <th class="text-left">Date</th>
                        <th class="text-left">Issue</th>
                        <th class="text-left">Severity</th>
                        <th class="text-left">Status</th>
                        <th class="text-left">Assigned to</th>
                        <th class="text-right"><span class="sr-only">Action</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($p->incidents as $incident)
                        <tr wire:key="pi-{{ $incident->id }}">
                            <td class="tabular whitespace-nowrap text-ink-muted">{{ $incident->occurred_on->format('j M Y') }}</td>
                            <td class="max-w-md">
                                <span class="block font-medium text-ink">{{ $incident->type->label() }}</span>
                                <span class="block truncate text-xs text-ink-subtle">{{ $incident->description }}</span>
                            </td>
                            <td><x-ui.pill :tone="$incident->severity->tone()" :dot="false">{{ $incident->severity->label() }}</x-ui.pill></td>
                            <td><x-ui.pill :tone="$incident->status->tone()">{{ $incident->status->label() }}</x-ui.pill></td>
                            <td class="text-ink-muted">{{ $incident->assignee?->name ?? '—' }}</td>
                            <td class="text-right">
                                @if (\App\Actions\Travel\Providers\SaveProviderIncident::canUpdate(auth()->user(), $incident))
                                    <x-ui.button size="sm" variant="secondary" wire:click="openIncident({{ $incident->id }})">Update</x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state icon="check-circle" title="No incidents recorded" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.table-card>
    </div>

    {{-- Performance --}}
    <div x-show="tab === 'performance'" x-cloak class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
        <x-ui.stat label="Packages" :value="number_format($performance['packages'])" />
        <x-ui.stat label="Bookings sold" :value="number_format($performance['bookings'])" />
        <x-ui.stat label="Revenue" :value="$kes($performance['revenue'])" />
        <x-ui.stat label="Slots sold" :value="number_format($performance['slots'])" />
        <x-ui.stat label="Cancellations" :value="number_format($performance['cancellations'])" />
        <x-ui.stat label="Refunded" :value="$kes($performance['refunds'])" />
        <x-ui.stat label="Media files" :value="number_format($performance['media'])" />
        <x-ui.stat label="Open incidents" :value="number_format($performance['openIncidents'])" />
    </div>

    @include('livewire.travel.providers.partials.contract-modal')
    @include('livewire.travel.providers.partials.incident-modal')
</div>
