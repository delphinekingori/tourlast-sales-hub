@php
    $selectClass = 'h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="Provider incidents" description="Driver no-shows, guide and vehicle issues, complaints, safety concerns and provider cancellations, with who is handling each.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="building" :href="route('travel.providers.index')" wire:navigate>Providers</x-ui.button>
            <x-ui.button icon="plus" wire:click="openIncident">Record incident</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-4">
        @foreach ([
            ['Open', $summary['open'], $summary['open'] ? 'text-warning' : 'text-ink'],
            ['Open and high / critical', $summary['serious'], $summary['serious'] ? 'text-danger' : 'text-ink'],
            ['Recorded this month', $summary['month'], 'text-ink'],
            ['Resolved or closed', $summary['resolved'], 'text-success'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular text-xl leading-tight font-bold {{ $tone }}">{{ number_format($value) }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="flex flex-wrap items-center gap-2 rounded-xl border border-line bg-surface p-3 shadow-card">
        <select wire:model.live="provider" aria-label="Provider" class="{{ $selectClass }} max-w-64">
            <option value="">All providers</option>
            @foreach ($providers as $option)
                <option value="{{ $option->id }}">{{ $option->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="severity" aria-label="Severity" class="{{ $selectClass }}">
            <option value="">Any severity</option>
            @foreach (\App\Enums\Travel\IncidentSeverity::cases() as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>
        <select wire:model.live="status" aria-label="Status" class="{{ $selectClass }}">
            <option value="">Any status</option>
            @foreach (\App\Enums\Travel\IncidentStatus::cases() as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>
        <select wire:model.live="type" aria-label="Issue type" class="{{ $selectClass }}">
            <option value="">Any issue</option>
            @foreach (\App\Enums\Travel\IncidentType::cases() as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>
        @if ($hasFilters)
            <button type="button" wire:click="clearFilters" class="ml-1 text-[13px] font-medium text-brand-text hover:underline">Clear all</button>
        @endif
    </div>

    <x-ui.table-card :paginator="$incidents">
        <table class="w-full min-w-[1080px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Date</th>
                    <th class="text-left">Provider</th>
                    <th class="text-left">Issue</th>
                    <th class="text-left">Severity</th>
                    <th class="text-left">Status</th>
                    <th class="text-left">Assigned to</th>
                    <th class="text-left">Reported by</th>
                    <th class="text-right"><span class="sr-only">Action</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($incidents as $incident)
                    <tr wire:key="inc-{{ $incident->id }}">
                        <td class="tabular whitespace-nowrap text-ink-muted">{{ $incident->occurred_on->format('j M Y') }}</td>
                        <td class="min-w-44"><a href="{{ route('travel.providers.show', $incident->travel_provider_id) }}" wire:navigate class="font-medium text-ink hover:text-brand-text">{{ $incident->provider->name }}</a>
                            @if ($incident->package)<span class="block text-xs text-ink-subtle">{{ $incident->package->name }}</span>@endif
                        </td>
                        <td class="max-w-md">
                            <span class="block font-medium text-ink">{{ $incident->type->label() }}</span>
                            <span class="block truncate text-xs text-ink-subtle">{{ $incident->description }}</span>
                            @if ($incident->resolution)<span class="block truncate text-xs text-success">Resolution: {{ $incident->resolution }}</span>@endif
                        </td>
                        <td><x-ui.pill :tone="$incident->severity->tone()" :dot="false">{{ $incident->severity->label() }}</x-ui.pill></td>
                        <td><x-ui.pill :tone="$incident->status->tone()">{{ $incident->status->label() }}</x-ui.pill></td>
                        <td class="whitespace-nowrap text-ink-muted">{{ $incident->assignee?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap text-ink-muted">{{ $incident->reporter?->name }}</td>
                        <td class="text-right">
                            @if (\App\Actions\Travel\Providers\SaveProviderIncident::canUpdate(auth()->user(), $incident))
                                <x-ui.button size="sm" variant="secondary" wire:click="openIncident({{ $incident->id }})">Update</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-ui.empty-state icon="check-circle" title="No incidents match these filters" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    @include('livewire.travel.providers.partials.incident-modal')
</div>
