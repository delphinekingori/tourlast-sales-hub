@php
    $selectClass = 'h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="Contracts" description="Provider contracts and when they end. A package cannot be published while its provider's contract is expired or not yet active.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="building" :href="route('travel.providers.index')" wire:navigate>Providers</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-4">
        @foreach ([
            ['Active contracts', $summary['active'], 'text-success'],
            ['Expiring in 30 days', $summary['expiring'], $summary['expiring'] ? 'text-warning' : 'text-ink'],
            ['Expired (still marked active)', $summary['expired'], $summary['expired'] ? 'text-danger' : 'text-ink'],
            ['Draft or awaiting approval', $summary['pending'], 'text-brand-text'],
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
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search contract number, type or provider..." wide aria-label="Search contracts" />
            </div>
            <select wire:model.live="status" aria-label="Status" class="{{ $selectClass }}">
                <option value="">All statuses</option>
                @foreach (\App\Enums\Travel\ContractStatus::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="provider" aria-label="Provider" class="{{ $selectClass }} max-w-60">
                <option value="">All providers</option>
                @foreach ($providers as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="model" aria-label="Commission model" class="{{ $selectClass }}">
                <option value="">Any commission model</option>
                @foreach (\App\Enums\Travel\CommissionModel::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
        @if ($hasFilters)
            <div class="flex flex-wrap items-center gap-2 text-[13px]">
                <span class="font-medium text-ink">{{ number_format($contracts->total()) }} {{ \Illuminate\Support\Str::plural('contract', $contracts->total()) }} found</span>
                <button type="button" wire:click="clearFilters" class="font-medium text-brand-text hover:underline">Clear all</button>
            </div>
        @endif
    </div>

    <x-ui.table-card :paginator="$contracts">
        <table class="w-full min-w-[1080px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Contract</th>
                    <th class="text-left">Provider</th>
                    <th class="text-left">Type</th>
                    <th class="text-left">Starts</th>
                    <th class="text-left">Ends</th>
                    <th class="text-left">Status</th>
                    <th class="text-left">Commission</th>
                    <th class="text-right">Document</th>
                    <th class="text-right"><span class="sr-only">Action</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($contracts as $contract)
                    @php
                        $state = $contract->effectiveStatus();
                        $days = $contract->daysUntilExpiry();
                        $showCommission = $canSeeAllCommission || $contract->provider->owner_id === auth()->id();
                    @endphp
                    <tr wire:key="ct-{{ $contract->id }}">
                        <td class="font-semibold text-ink"><a href="{{ route('travel.contracts.show', $contract->id) }}" wire:navigate class="hover:text-brand-text">{{ $contract->contract_number }}</a></td>
                        <td class="min-w-48">
                            <a href="{{ route('travel.providers.show', $contract->travel_provider_id) }}" wire:navigate class="grid leading-tight">
                                <span class="truncate text-ink hover:text-brand-text">{{ $contract->provider->name }}</span>
                                <span class="truncate text-xs text-ink-subtle">{{ $contract->provider->owner?->name ?? 'Unassigned' }}</span>
                            </a>
                        </td>
                        <td class="text-ink-muted">{{ $contract->contract_type }}</td>
                        <td class="tabular whitespace-nowrap text-ink-muted">{{ $contract->starts_on->format('j M Y') }}</td>
                        <td class="tabular whitespace-nowrap">
                            @if ($contract->ends_on)
                                <span class="grid leading-tight">
                                    <span class="text-ink">{{ $contract->ends_on->format('j M Y') }}</span>
                                    @if ($contract->status === \App\Enums\Travel\ContractStatus::Active)
                                        <span @class(['text-xs', 'text-danger' => $days < 0, 'text-warning' => $days >= 0 && $days <= 30, 'text-ink-subtle' => $days > 30])>
                                            {{ $days < 0 ? 'Ended '.abs($days).' days ago' : ($days === 0 ? 'Ends today' : 'In '.$days.' days') }}
                                        </span>
                                    @endif
                                </span>
                            @else
                                <span class="text-ink-subtle">Open-ended</span>
                            @endif
                        </td>
                        <td><x-ui.pill :tone="$state->tone()">{{ $state->label() }}</x-ui.pill></td>
                        <td class="whitespace-nowrap text-ink-muted">
                            {{ $contract->commission_model->label() }}
                            @if ($showCommission && $contract->commission_rate !== null) · {{ rtrim(rtrim((string) $contract->commission_rate, '0'), '.') }}% @endif
                            @if ($showCommission && $contract->fixed_commission !== null) · {{ $contract->currency }} {{ number_format((float) $contract->fixed_commission) }} @endif
                        </td>
                        <td class="text-right text-[13px]">@include('livewire.travel.contracts.partials.document-cell', ['visible' => $showCommission])</td>
                        <td class="text-right"><x-ui.button size="sm" variant="secondary" :href="route('travel.contracts.show', $contract->id)" wire:navigate>Open</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="9"><x-ui.empty-state icon="document" title="No contracts match these filters" description="Contracts are created from a provider's page." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>
</div>
