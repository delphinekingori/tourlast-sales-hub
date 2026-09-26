@php
    $objectionMax = max(1, (int) $byObjection->max());
    $competitorMax = max(1, (int) $byCompetitor->max());
    $typeMax = max(1, (int) $byType->max());
    $explainedTotal = max(1, (int) $byObjection->sum());
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="Lost & objections" description="Why properties say no to Tourlast, who they choose instead, and which lost properties are due to be approached again. Combines lost leads and lost or rejected registry records.">
        <x-slot:actions>
            <x-ui.segmented wire:model.live="period" :options="\App\Livewire\Insights\Objections::Periods" />
        </x-slot:actions>
    </x-ui.page-header>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-5" wire:loading.delay.class="opacity-60">
        @foreach ([
            ['Properties lost', number_format($losses->count()), 'text-ink'],
            ['Top objection', $topObjection?->label() ?? '—', 'text-ink'],
            ['Lost to competitors', $competitorShare.'%', 'text-ink'],
            ['Lost without a reason', $unexplained, $unexplained ? 'text-danger' : 'text-success'],
            ['Re-engagements (120 days)', $upcoming->count(), 'text-brand-text'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="truncate text-xl leading-tight font-bold {{ $tone }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <x-ui.card title="Why hotels refuse Tourlast" description="Primary objection recorded when a property was lost">
            @if ($byObjection->isEmpty())
                <x-ui.empty-state icon="chart" title="No objections recorded in this period" />
            @else
                <dl class="grid gap-2">
                    @foreach ($byObjection as $value => $count)
                        <div class="grid grid-cols-[180px_1fr_72px] items-center gap-3 text-[13px]">
                            <dt class="truncate text-ink">{{ \App\Enums\Objection::from($value)->label() }}</dt>
                            <div class="h-2 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-brand" style="width: {{ round($count / $objectionMax * 100) }}%"></div></div>
                            <dd class="tabular text-right text-ink"><span class="font-semibold">{{ $count }}</span> <span class="text-xs text-ink-subtle">{{ round($count / $explainedTotal * 100) }}%</span></dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </x-ui.card>

        <x-ui.card title="Who they chose instead" description="Competitor named on the loss">
            @if ($byCompetitor->isEmpty())
                <x-ui.empty-state icon="chart" title="No competitors recorded" />
            @else
                <dl class="grid gap-2">
                    @foreach ($byCompetitor as $name => $count)
                        <div class="grid grid-cols-[130px_1fr_32px] items-center gap-3 text-[13px]">
                            <dt class="truncate text-ink">{{ $name }}</dt>
                            <div class="h-2 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-danger/60" style="width: {{ round($count / $competitorMax * 100) }}%"></div></div>
                            <dd class="tabular text-right font-semibold text-ink">{{ $count }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </x-ui.card>
    </div>

    <div class="grid items-start gap-4 xl:grid-cols-2">
        <x-ui.card title="Losses by salesperson" :padding="false">
            <table class="w-full text-sm">
                <thead class="text-left uppercase"><tr><th class="text-left">Salesperson</th><th class="text-right">Lost</th><th class="text-left">Most common objection</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @forelse ($byRep as $name => $row)
                        <tr><td class="font-medium text-ink">{{ $name }}</td><td class="tabular text-right text-ink">{{ $row['count'] }}</td><td class="text-ink-muted">{{ $row['top'] ?? 'No reason recorded' }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="text-ink-subtle">No losses in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>

        <x-ui.card title="Losses by property type">
            @if ($byType->isEmpty())
                <x-ui.empty-state icon="building" title="No losses in this period" />
            @else
                <dl class="grid gap-2">
                    @foreach ($byType as $type => $count)
                        <div class="grid grid-cols-[150px_1fr_32px] items-center gap-3 text-[13px]">
                            <dt class="truncate text-ink">{{ $type }}</dt>
                            <div class="h-2 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-sky" style="width: {{ round($count / $typeMax * 100) }}%"></div></div>
                            <dd class="tabular text-right font-semibold text-ink">{{ $count }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </x-ui.card>
    </div>

    <x-ui.table-card :sticky="false">
        <div class="flex items-center justify-between border-b border-line px-4 py-2.5">
            <h2 class="text-sm font-semibold text-ink">Upcoming re-engagements</h2>
            <span class="text-xs text-ink-subtle">Next 120 days, including overdue</span>
        </div>
        <table class="w-full min-w-[720px] text-sm">
            <thead class="text-left uppercase"><tr><th class="text-left">Re-engage</th><th class="text-left">Property</th><th class="text-left">Lost</th><th class="text-left">Objection</th><th class="text-left">Salesperson</th></tr></thead>
            <tbody class="divide-y divide-line">
                @forelse ($upcoming as $item)
                    <tr>
                        <td @class(['tabular font-semibold', 'text-danger' => $item['date']->isPast() && ! $item['date']->isToday(), 'text-ink' => ! ($item['date']->isPast() && ! $item['date']->isToday())])>{{ $item['date']->format('j M Y') }}</td>
                        <td><a href="{{ $item['url'] }}" wire:navigate class="font-medium text-ink hover:text-brand-text">{{ $item['name'] }}</a></td>
                        <td class="text-ink-muted">{{ $item['lost']?->format('M Y') ?? '—' }}</td>
                        <td class="text-ink-muted">{{ $item['objection']?->label() ?? '—' }}</td>
                        <td class="text-ink-muted">{{ $item['rep'] ?? 'Unassigned' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty-state icon="clock" title="No re-engagements scheduled" description="Add a re-engage date when marking a property lost, and it will come back round." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    <x-ui.table-card>
        <div class="border-b border-line px-4 py-2.5"><h2 class="text-sm font-semibold text-ink">Losses in this period</h2></div>
        <table class="w-full min-w-[900px] text-sm">
            <thead class="text-left uppercase"><tr><th class="text-left">Date</th><th class="text-left">Property</th><th class="text-left">Objection</th><th class="text-left">Competitor</th><th class="text-left">Notes</th><th class="text-left">Salesperson</th></tr></thead>
            <tbody class="divide-y divide-line">
                @forelse ($losses as $loss)
                    <tr>
                        <td class="tabular text-ink-muted">{{ $loss['date']?->format('j M Y') }}</td>
                        <td><a href="{{ $loss['url'] }}" wire:navigate class="grid leading-tight"><span class="font-medium text-ink hover:text-brand-text">{{ $loss['name'] }}</span><span class="text-xs text-ink-subtle">{{ $loss['type'] }} · {{ $loss['kind'] === 'registry' ? 'Registry' : 'Lead' }}</span></a></td>
                        <td>@if ($loss['objection'])<x-ui.pill tone="danger" :dot="false">{{ $loss['objection']->label() }}</x-ui.pill>@else<span class="text-xs font-medium text-danger">No reason recorded</span>@endif</td>
                        <td class="text-ink-muted">{{ $loss['competitor'] ?? '—' }}</td>
                        <td class="max-w-72 text-ink-muted"><span class="line-clamp-2 text-xs">{{ $loss['notes'] ?? '—' }}</span></td>
                        <td class="text-ink-muted">{{ $loss['rep'] ?? 'Unassigned' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-ui.empty-state icon="check-circle" title="No properties lost in this period" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>
</div>
