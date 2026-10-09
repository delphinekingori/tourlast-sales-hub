@php
    $selectClass = 'h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="Providers" description="Tour operators, safari and experience providers, DMCs and other travel partners. Search here before adding a provider.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="document" :href="route('travel.contracts.index')" wire:navigate>Contracts</x-ui.button>
            <x-ui.button variant="secondary" icon="alert" :href="route('travel.incidents.index')" wire:navigate>Incidents</x-ui.button>
            <x-ui.button icon="plus" :href="route('travel.providers.create')" wire:navigate>Add provider</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-4">
        @foreach ([
            ['Active providers', $summary['active'], 'text-success'],
            ['Prospects in the pipeline', $summary['prospects'], 'text-brand-text'],
            ['With a contract in force', $summary['contracted'], 'text-ink'],
            ['Contracts expiring (30 days)', $summary['expiring'], $summary['expiring'] ? 'text-warning' : 'text-ink'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular text-xl leading-tight font-bold {{ $tone }}">{{ number_format($value) }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="grid gap-3 rounded-xl border border-line bg-surface p-3 shadow-card">
        <div class="flex flex-wrap items-center gap-2">
            <div class="min-w-56 flex-1">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search provider, trading name, town, contact, email..." wide aria-label="Search providers" />
            </div>
            <select wire:model.live="type" aria-label="Provider type" class="{{ $selectClass }}">
                <option value="">All types</option>
                @foreach (\App\Enums\Travel\TravelProviderType::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="status" aria-label="Status" class="{{ $selectClass }}">
                <option value="">All statuses</option>
                @foreach (\App\Enums\Travel\TravelProviderStatus::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="contract" aria-label="Contract" class="{{ $selectClass }}">
                <option value="">Any contract</option>
                @foreach (\App\Livewire\Travel\Providers\Index::ContractStates as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @if ($canManageAll)
                <select wire:model.live="owner" aria-label="Salesperson" class="{{ $selectClass }}">
                    <option value="">All salespeople</option>
                    @foreach ($owners as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-2 px-1 text-[13px] text-ink-muted">
                    <input type="checkbox" wire:model.live="archived" class="size-4 accent-[var(--tl-brand)]"> Archived
                </label>
            @endif
            <label class="flex items-center gap-2 px-1 text-[13px] text-ink-muted">
                <input type="checkbox" wire:model.live="mine" class="size-4 accent-[var(--tl-brand)]"> Mine only
            </label>
        </div>

        @if ($hasFilters)
            <div class="flex flex-wrap items-center gap-2 text-[13px]">
                <span class="font-medium text-ink">{{ number_format($providers->total()) }} {{ \Illuminate\Support\Str::plural('provider', $providers->total()) }} found</span>
                <button type="button" wire:click="clearFilters" class="font-medium text-brand-text hover:underline">Clear all</button>
            </div>
        @endif
    </div>

    <x-ui.table-card :paginator="$providers">
        <table class="w-full min-w-[1080px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Provider</th>
                    <th class="text-left">Type</th>
                    <th class="text-left">Location</th>
                    <th class="text-left">Status</th>
                    <th class="text-left">Active contract</th>
                    <th class="text-right">Packages</th>
                    <th class="text-left">Salesperson</th>
                    <th class="text-left">Updated</th>
                    <th class="text-right"><span class="sr-only">Action</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($providers as $provider)
                    @php
                        $contract = $provider->activeContract();
                        $expired = $contract === null && $provider->contracts->contains(fn ($c) => $c->ends_on?->isPast());
                    @endphp
                    <tr wire:key="tp-{{ $provider->id }}">
                        <td class="max-w-72 min-w-56">
                            <a href="{{ route('travel.providers.show', $provider->id) }}" wire:navigate class="grid leading-tight">
                                <span class="truncate font-semibold text-ink hover:text-brand-text">{{ $provider->name }}</span>
                                <span class="truncate text-xs text-ink-subtle">
                                    {{ $provider->primary_contact_name ?? 'No contact' }}{{ $provider->phone ? ' · '.$provider->phone : '' }}
                                    @if ($provider->archived_at) · <span class="text-danger">Archived</span> @endif
                                </span>
                            </a>
                        </td>
                        <td class="whitespace-nowrap text-ink-muted">{{ $provider->provider_type->label() }}</td>
                        <td class="min-w-36 text-ink-muted">{{ collect([$provider->city, $provider->region])->filter()->implode(', ') ?: '—' }}</td>
                        <td class="whitespace-nowrap"><x-ui.pill :tone="$provider->status->tone()">{{ $provider->status->label() }}</x-ui.pill></td>
                        <td class="whitespace-nowrap">
                            @if ($contract)
                                @php $state = $contract->effectiveStatus(); @endphp
                                <span class="grid leading-tight">
                                    <span class="text-ink">{{ $contract->contract_number }}</span>
                                    <span @class(['text-xs', 'text-warning' => $state === \App\Enums\Travel\ContractStatus::ExpiringSoon, 'text-ink-subtle' => $state !== \App\Enums\Travel\ContractStatus::ExpiringSoon])>
                                        {{ $contract->ends_on ? 'Ends '.$contract->ends_on->format('j M Y').($state === \App\Enums\Travel\ContractStatus::ExpiringSoon ? ' · '.$contract->daysUntilExpiry().' days' : '') : 'No end date' }}
                                    </span>
                                </span>
                            @elseif ($expired)
                                <x-ui.pill tone="danger">Expired</x-ui.pill>
                            @else
                                <span class="text-ink-subtle">None</span>
                            @endif
                        </td>
                        <td class="tabular text-right text-ink">{{ $provider->packages_count }}</td>
                        <td class="whitespace-nowrap">
                            @if ($provider->owner)
                                <span class="flex items-center gap-2"><x-ui.avatar :user="$provider->owner" size="sm" /><span class="text-ink">{{ $provider->owner->name }}</span></span>
                            @else
                                <span class="text-ink-subtle">Unassigned</span>
                            @endif
                        </td>
                        <td class="tabular whitespace-nowrap text-ink-muted">{{ $provider->updated_at->format('j M Y') }}</td>
                        <td class="text-right"><x-ui.button size="sm" variant="secondary" :href="route('travel.providers.show', $provider->id)" wire:navigate>View</x-ui.button></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <x-ui.empty-state icon="building"
                                :title="$search !== '' ? 'No provider matches “'.$search.'”' : 'No providers match these filters'"
                                :description="$search !== '' ? 'Tourlast has no provider by that name yet. You can add it.' : 'Clear some filters to see more providers.'" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>
</div>
