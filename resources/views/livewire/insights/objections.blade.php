@php
    $objectionMax = max(1, (int) $byObjection->max());
    $competitorMax = max(1, (int) $byCompetitor->max());
    $typeMax = max(1, (int) $byType->max());
    $explainedTotal = max(1, (int) $byObjection->sum());
    $isOverdue = fn ($item): bool => $item['date']->isPast() && ! $item['date']->isToday();
    $overdue = $upcoming->filter($isOverdue)->values();
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="Lost & objections" description="Why properties say no to Tourlast, who they choose instead, and which lost properties are due to be approached again. Combines lost leads and lost or rejected registry records." />

    <p class="text-sm text-ink-muted">
        @if ($losses->isEmpty())
            <span class="font-semibold text-success">No properties lost in this period.</span>
        @else
            {{ number_format($losses->count()) }} {{ \Illuminate\Support\Str::plural('property', $losses->count()) }} lost{{ $topObjection ? ', most often because of “'.$topObjection->label().'”' : '' }}.
            @if ($unexplained)
                <span class="font-semibold text-warning">{{ $unexplained }} {{ $unexplained === 1 ? 'has' : 'have' }} no reason recorded.</span>
            @endif
        @endif
    </p>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card max-md:[&>*:last-child:nth-child(odd)]:col-span-2 md:grid-cols-5" wire:loading.delay.class="opacity-60">
        <x-ui.kpi size="lg" label="Properties lost" :value="number_format($losses->count())" />
        <x-ui.kpi size="lg" label="Top objection" :value="$topObjection?->label() ?? '—'" />
        <x-ui.kpi size="lg" label="Lost to competitors" :value="$competitorShare.'%'" />
        <x-ui.kpi size="lg" label="Lost without a reason" :value="$unexplained" :tone="$unexplained ? 'text-danger' : 'text-success'" />
        <x-ui.kpi size="lg" label="Re-engagements (120 days)" :value="$upcoming->count()" tone="text-brand-text" />
    </dl>

    <div class="max-w-full overflow-x-auto">
        <x-ui.segmented wire:model.live="period" :options="\App\Livewire\Insights\Objections::Periods" />
    </div>

    <div class="grid gap-5" wire:loading.delay.class="opacity-60">
        @if ($overdue->isNotEmpty())
            <x-ui.card title="Needs attention" description="Re-engagements that are overdue." :padding="false">
                @foreach ($overdue->take(5) as $item)
                    <a href="{{ $item['url'] }}" wire:navigate wire:key="obj-due-{{ $loop->index }}" class="flex min-h-12 items-center justify-between gap-3 border-b border-line px-4 py-2 last:border-b-0 hover:bg-surface-muted/50">
                        <span class="grid min-w-0 leading-tight">
                            <span class="truncate text-sm font-semibold text-ink">{{ $item['name'] }}</span>
                            <span class="truncate text-xs text-ink-subtle">{{ $item['rep'] ?? 'Unassigned' }}</span>
                        </span>
                        <span class="shrink-0 text-xs font-semibold text-danger">Overdue since {{ $item['date']->format('j M') }}</span>
                    </a>
                @endforeach
                @if ($overdue->count() > 5)
                    <p class="px-4 py-2 text-xs text-ink-subtle">And {{ $overdue->count() - 5 }} more in the list below.</p>
                @endif
            </x-ui.card>
        @endif

        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <x-ui.card title="Why hotels refuse Tourlast" description="Primary objection recorded when a property was lost">
                @if ($byObjection->isEmpty())
                    <x-ui.empty-state icon="chart" title="No objections recorded in this period" />
                @else
                    <dl class="grid gap-3">
                        @foreach ($byObjection as $value => $count)
                            @php
                                $barWidth = (int) round($count / $objectionMax * 100);
                                $share = (int) round($count / $explainedTotal * 100);
                            @endphp
                            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 text-sm sm:grid-cols-[180px_1fr_72px]">
                                <dt class="order-1 truncate text-ink">{{ \App\Enums\Objection::from($value)->label() }}</dt>
                                <div class="order-3 col-span-2 h-2 overflow-hidden rounded-full bg-surface-muted sm:order-2 sm:col-span-1" role="progressbar" aria-label="{{ \App\Enums\Objection::from($value)->label() }}" aria-valuenow="{{ $barWidth }}" aria-valuemin="0" aria-valuemax="100" aria-valuetext="{{ $count }} lost, {{ $share }}%"><div class="h-full rounded-full bg-brand" style="width: {{ $barWidth }}%"></div></div>
                                <dd class="tabular order-2 text-right text-ink sm:order-3"><span class="font-semibold">{{ $count }}</span> <span class="text-xs text-ink-subtle">{{ $share }}%</span></dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </x-ui.card>

            <x-ui.card title="Who they chose instead" description="Competitor named on the loss">
                @if ($byCompetitor->isEmpty())
                    <x-ui.empty-state icon="chart" title="No competitors recorded" />
                @else
                    <dl class="grid gap-3">
                        @foreach ($byCompetitor as $name => $count)
                            @php($barWidth = (int) round($count / $competitorMax * 100))
                            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 text-sm sm:grid-cols-[130px_1fr_32px]">
                                <dt class="order-1 truncate text-ink">{{ $name }}</dt>
                                <div class="order-3 col-span-2 h-2 overflow-hidden rounded-full bg-surface-muted sm:order-2 sm:col-span-1" role="progressbar" aria-label="{{ $name }}" aria-valuenow="{{ $barWidth }}" aria-valuemin="0" aria-valuemax="100" aria-valuetext="{{ $count }} lost"><div class="h-full rounded-full bg-danger/60" style="width: {{ $barWidth }}%"></div></div>
                                <dd class="tabular order-2 text-right font-semibold text-ink sm:order-3">{{ $count }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </x-ui.card>
        </div>

        <div class="grid items-start gap-4 xl:grid-cols-2">
            <x-ui.card title="Losses by salesperson" :padding="false">
                <div class="overflow-x-auto">
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
                </div>
            </x-ui.card>

            <x-ui.card title="Losses by property type">
                @if ($byType->isEmpty())
                    <x-ui.empty-state icon="building" title="No losses in this period" />
                @else
                    <dl class="grid gap-3">
                        @foreach ($byType as $type => $count)
                            @php($barWidth = (int) round($count / $typeMax * 100))
                            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 text-sm sm:grid-cols-[150px_1fr_32px]">
                                <dt class="order-1 truncate text-ink">{{ $type }}</dt>
                                <div class="order-3 col-span-2 h-2 overflow-hidden rounded-full bg-surface-muted sm:order-2 sm:col-span-1" role="progressbar" aria-label="{{ $type }}" aria-valuenow="{{ $barWidth }}" aria-valuemin="0" aria-valuemax="100" aria-valuetext="{{ $count }} lost"><div class="h-full rounded-full bg-sky" style="width: {{ $barWidth }}%"></div></div>
                                <dd class="tabular order-2 text-right font-semibold text-ink sm:order-3">{{ $count }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card title="Upcoming re-engagements" description="Next 120 days, including overdue" :padding="false">
            {{-- Phones: one card per property --}}
            <ul class="divide-y divide-line sm:hidden">
                @forelse ($upcoming as $item)
                    <li>
                        <a href="{{ $item['url'] }}" wire:navigate class="flex min-h-12 items-center justify-between gap-3 px-4 py-2.5">
                            <span class="grid min-w-0 leading-tight">
                                <span class="truncate font-semibold text-ink">{{ $item['name'] }}</span>
                                <span class="truncate text-xs text-ink-subtle">{{ $item['rep'] ?? 'Unassigned' }} · Lost {{ $item['lost']?->format('M Y') ?? '—' }}{{ $item['objection'] ? ' · '.$item['objection']->label() : '' }}</span>
                            </span>
                            <span @class(['tabular shrink-0 text-xs font-semibold', 'text-danger' => $isOverdue($item), 'text-ink' => ! $isOverdue($item)])>{{ $item['date']->format('j M Y') }}</span>
                        </a>
                    </li>
                @empty
                    <li><x-ui.empty-state icon="clock" title="No re-engagements scheduled" description="Add a re-engage date when marking a property lost, and it will come back round." /></li>
                @endforelse
            </ul>

            {{-- Tablets and up: the table --}}
            <x-ui.table-card :sticky="false" class="hidden rounded-none border-0 shadow-none sm:block">
                <table class="w-full min-w-[720px] text-sm">
                    <thead class="text-left uppercase"><tr><th class="text-left">Re-engage</th><th class="text-left">Property</th><th class="text-left">Lost</th><th class="text-left">Objection</th><th class="text-left">Salesperson</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($upcoming as $item)
                            <tr>
                                <td @class(['tabular font-semibold', 'text-danger' => $isOverdue($item), 'text-ink' => ! $isOverdue($item)])>{{ $item['date']->format('j M Y') }}</td>
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
        </x-ui.card>

        <x-ui.card title="Losses in this period" :padding="false">
            {{-- Phones: one card per loss --}}
            <ul class="divide-y divide-line sm:hidden">
                @forelse ($losses as $loss)
                    <li class="grid gap-1.5 px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <a href="{{ $loss['url'] }}" wire:navigate class="grid min-h-10 min-w-0 content-center leading-tight">
                                <span class="font-semibold text-ink">{{ $loss['name'] }}</span>
                                <span class="text-xs text-ink-subtle">{{ $loss['type'] }} · {{ $loss['kind'] === 'registry' ? 'Registry' : 'Lead' }} · {{ $loss['rep'] ?? 'Unassigned' }}</span>
                            </a>
                            <span class="tabular shrink-0 text-xs text-ink-muted">{{ $loss['date']?->format('j M Y') }}</span>
                        </div>
                        <div>@if ($loss['objection'])<x-ui.pill tone="danger" :dot="false">{{ $loss['objection']->label() }}</x-ui.pill>@else<span class="text-xs font-medium text-danger">No reason recorded</span>@endif</div>
                        @if ($loss['competitor'] ?? null)<p class="text-xs text-ink-muted">Chose: {{ $loss['competitor'] }}</p>@endif
                        @if ($loss['notes'] ?? null)<p class="line-clamp-3 text-xs text-ink-muted">{{ $loss['notes'] }}</p>@endif
                    </li>
                @empty
                    <li><x-ui.empty-state icon="check-circle" title="No properties lost in this period" /></li>
                @endforelse
            </ul>

            {{-- Tablets and up: the table --}}
            <x-ui.table-card class="hidden rounded-none border-0 shadow-none sm:block">
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
        </x-ui.card>
    </div>
</div>
