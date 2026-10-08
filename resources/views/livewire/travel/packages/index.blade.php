<div class="grid gap-5">
    <x-ui.page-header title="Packages" description="Tour and experience packages. Every package needs Sales Admin and Super Admin approval before it can be published, and changes to a live package go back for approval.">
        <x-slot:actions>
            <x-ui.button icon="plus" :href="route('travel.packages.create')" wire:navigate>New package</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Summary --}}
    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-3 xl:grid-cols-6">
        @foreach ([
            ['Drafts', $summary['drafts'], 'text-ink', ['status' => 'draft']],
            ['Pending approval', $summary['pending'], $summary['pending'] ? 'text-warning' : 'text-ink', ['approval' => 'awaiting_sales_admin']],
            ['Approved, not published', $summary['approved'], 'text-brand-text', ['status' => 'approved']],
            ['Published', $summary['published'], 'text-success', ['status' => 'published']],
            ['Approval required', $summary['approval_required'], $summary['approval_required'] ? 'text-danger' : 'text-ink', ['approval' => 'approval_required']],
            ['Low availability', $summary['low_availability'], $summary['low_availability'] ? 'text-warning' : 'text-ink', null],
        ] as [$label, $value, $tone, $filter])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular text-xl leading-tight font-bold {{ $tone }}">
                    @if ($filter)
                        <button type="button" wire:click="$set('{{ array_key_first($filter) }}', '{{ reset($filter) }}')" class="hover:underline">{{ number_format($value) }}</button>
                    @else
                        {{ number_format($value) }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>

    {{-- Search and filters --}}
    <div class="grid gap-3 rounded-xl border border-line bg-surface p-3 shadow-card">
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search package, reference, destination or provider" wide class="sm:w-96" />
            <select wire:model.live="status" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink" aria-label="Publication status">
                <option value="">All statuses</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="approval" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink" aria-label="Approval">
                <option value="">Any approval state</option>
                @foreach (\App\Support\Travel\PackageListing::ApprovalFilters as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="provider" class="h-9 max-w-52 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink" aria-label="Provider">
                <option value="">All providers</option>
                @foreach ($providers as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="destination" class="h-9 max-w-48 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink" aria-label="Destination">
                <option value="">All destinations</option>
                @foreach ($destinations as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
            <select wire:model.live="owner" class="h-9 max-w-48 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink" aria-label="Created by">
                <option value="">Everyone's packages</option>
                <option value="mine">My packages</option>
                @foreach ($owners as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="contract" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink" aria-label="Contract">
                <option value="">Any contract</option>
                <option value="problem">No contract in force</option>
            </select>
            @if ($hasFilters)
                <x-ui.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">Clear</x-ui.button>
            @endif
        </div>
    </div>

    <x-ui.table-card :paginator="$packages">
        <table class="w-full min-w-[1280px] text-left text-[13px]">
            <thead>
                <tr>
                    <th class="min-w-56">Package</th>
                    <th class="min-w-40">Provider</th>
                    <th>Destination</th>
                    <th>Next departure</th>
                    <th class="text-right">Price (adult)</th>
                    <th class="text-right">Slots</th>
                    <th class="text-right">Sold</th>
                    <th class="text-right">Available</th>
                    <th>Created by</th>
                    <th>Approval</th>
                    <th>Publication</th>
                    <th>Contract</th>
                    <th>Updated</th>
                    <th class="text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($packages as $package)
                    @php
                        $version = $package->workingVersion ?? $package->liveVersion;
                        $capacity = (int) $package->future_capacity;
                        $sold = (int) $package->future_sold;
                        $available = max(0, $capacity - $sold - (int) $package->future_reserved);
                        $contractStatus = $package->contract?->effectiveStatus();
                    @endphp
                    <tr wire:key="pkg-{{ $package->id }}">
                        <td>
                            <a href="{{ route('travel.packages.show', $package) }}" wire:navigate class="grid leading-tight hover:underline">
                                <span class="font-semibold text-ink">{{ $package->name }}</span>
                                <span class="text-xs text-ink-subtle">{{ $package->reference }} · {{ $package->typeLabel() }}</span>
                            </a>
                        </td>
                        <td class="text-ink-muted">{{ $package->provider?->name ?? '—' }}</td>
                        <td class="text-ink-muted">{{ $package->destination }}</td>
                        <td class="whitespace-nowrap text-ink-muted">
                            {{ $package->next_departure_on ? \Carbon\Carbon::parse($package->next_departure_on)->format('j M Y') : '—' }}
                        </td>
                        <td class="tabular text-right whitespace-nowrap">
                            {{ $version?->adult_price !== null ? $version->currency.' '.number_format((float) $version->adult_price) : '—' }}
                        </td>
                        <td class="tabular text-right">{{ $capacity ?: '—' }}</td>
                        <td class="tabular text-right">{{ $capacity ? $sold : '—' }}</td>
                        <td @class(['tabular text-right', 'font-semibold text-warning' => $capacity && $available > 0 && $available <= max(1, (int) ceil($capacity * 0.2)), 'font-semibold text-danger' => $capacity && $available === 0])>
                            {{ $capacity ? $available : '—' }}
                        </td>
                        <td class="text-ink-muted">{{ $package->creator?->name }}</td>
                        <td>
                            @include('livewire.travel.packages.partials.approval-pill', ['package' => $package])
                        </td>
                        <td><x-ui.pill :tone="$package->status->tone()">{{ $package->status->label() }}</x-ui.pill></td>
                        <td>
                            @if ($contractStatus)
                                <x-ui.pill :tone="$contractStatus->tone()">{{ $contractStatus->label() }}</x-ui.pill>
                            @else
                                <x-ui.pill tone="danger">No contract</x-ui.pill>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-ink-subtle">{{ $package->updated_at->diffForHumans(short: true) }}</td>
                        <td class="text-right">
                            @include('livewire.travel.packages.partials.row-actions', ['package' => $package])
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="14">
                            <x-ui.empty-state icon="map" title="No packages found" :description="$hasFilters ? 'Try clearing the filters.' : 'Create the first package for a contracted provider.'" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    @include('livewire.travel.packages.partials.action-modals')
</div>
