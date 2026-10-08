<div class="grid gap-5">
    <x-ui.page-header eyebrow="Admin" title="Audit log" description="Every important change: who made it, when, and what it was before and after. Entries can't be edited or deleted." />

    <div class="flex flex-wrap items-end gap-2 rounded-xl border border-line bg-surface p-3 shadow-card">
        <div class="min-w-56 flex-1">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search what happened…" />
        </div>
        <select wire:model.live="area" aria-label="Area" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
            <option value="">All areas</option>
            @foreach ($areas as $area)
                <option value="{{ $area }}">{{ ucfirst(str_replace('_', ' ', $area)) }}</option>
            @endforeach
        </select>
        <select wire:model.live="user" aria-label="Person" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
            <option value="">Anyone</option>
            @foreach ($people as $person)
                <option value="{{ $person->id }}">{{ $person->name }}</option>
            @endforeach
        </select>
        <input type="date" wire:model.live="from" aria-label="From" class="h-9 rounded-md border border-line-strong bg-surface px-2 text-[13px] text-ink focus:border-brand focus:outline-none" />
        <input type="date" wire:model.live="to" aria-label="To" class="h-9 rounded-md border border-line-strong bg-surface px-2 text-[13px] text-ink focus:border-brand focus:outline-none" />
    </div>

    <x-ui.table-card :paginator="$events">
        <table class="w-full min-w-[880px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">When</th>
                    <th class="text-left">Who</th>
                    <th class="text-left">Action</th>
                    <th class="text-left">What happened</th>
                    <th class="text-left">Changes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($events as $event)
                    <tr wire:key="audit-{{ $event->id }}" class="align-top">
                        <td class="tabular text-[13px] whitespace-nowrap text-ink-muted">{{ $event->created_at->format('j M Y, H:i') }}</td>
                        <td class="whitespace-nowrap text-ink">{{ $event->user?->name ?? 'System' }}</td>
                        <td class="whitespace-nowrap"><x-ui.pill :dot="false">{{ \App\Livewire\Admin\AuditLog::actionLabel($event->action) }}</x-ui.pill></td>
                        <td class="text-ink">{{ $event->summary }}</td>
                        <td class="text-[12.5px] text-ink-muted">
                            @foreach ((array) $event->changes as $field => $change)
                                <div class="break-words">
                                    <span class="font-medium text-ink">{{ str_replace('_', ' ', $field) }}:</span>
                                    {{ is_scalar($change[0] ?? null) || ($change[0] ?? null) === null ? \Illuminate\Support\Str::limit((string) ($change[0] ?? '—'), 60) : '…' }}
                                    →
                                    {{ is_scalar($change[1] ?? null) || ($change[1] ?? null) === null ? \Illuminate\Support\Str::limit((string) ($change[1] ?? '—'), 60) : '…' }}
                                </div>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty-state icon="shield" title="Nothing recorded yet" description="Changes to travel providers, contracts, packages, bookings, payments and targets appear here." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>
</div>
