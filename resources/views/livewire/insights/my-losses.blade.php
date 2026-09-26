@php
    $objectionMax = max(1, (int) $byObjection->max());
    $competitorMax = max(1, (int) $byCompetitor->max());
    $explainedTotal = max(1, (int) $byObjection->sum());
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="My losses" description="Properties that said no to you, why, who they chose instead, and when to try again. Only you and your managers see this.">
        <x-slot:actions>
            <x-ui.segmented wire:model.live="period" :options="\App\Livewire\Insights\Objections::Periods" />
        </x-slot:actions>
    </x-ui.page-header>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-4" wire:loading.delay.class="opacity-60">
        @foreach ([
            ['Properties lost', number_format($losses->count()), 'text-ink'],
            ['My most common objection', $topObjection?->label() ?? '—', 'text-ink'],
            ['Lost to competitors', $competitorShare.'%', 'text-ink'],
            ['Re-engagements coming up', $upcoming->count(), 'text-brand-text'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="truncate text-xl leading-tight font-bold {{ $tone }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($unexplained)
        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-warning/40 bg-warning-soft/50 px-4 py-2.5 text-[13px] text-ink">
            <x-ui.icon name="alert" class="size-4 text-warning" />
            {{ $unexplained }} lost {{ \Illuminate\Support\Str::plural('lead', $unexplained) }} {{ $unexplained === 1 ? 'has' : 'have' }} no objection recorded (marked lost before objections were tracked). They show as “No reason recorded” below.
        </div>
    @endif

    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        {{-- Upcoming re-engagements first: that's the salesperson's action list. --}}
        <x-ui.card title="Upcoming re-engagements" description="Lost properties due to be approached again in the next 120 days" :padding="false">
            @forelse ($upcoming as $item)
                <a href="{{ $item['url'] }}" wire:navigate class="flex items-center justify-between gap-3 border-b border-line px-4 py-2.5 last:border-b-0 hover:bg-surface-muted/50">
                    <span class="grid min-w-0 leading-tight">
                        <span class="truncate text-[13px] font-semibold text-ink">{{ $item['name'] }}</span>
                        <span class="truncate text-xs text-ink-subtle">Lost {{ $item['lost']?->format('M Y') ?? '—' }}{{ $item['objection'] ? ' · '.$item['objection']->label() : '' }}</span>
                    </span>
                    @php
                        $overdue = $item['date']->isPast() && ! $item['date']->isToday();
                    @endphp
                    <span @class(['shrink-0 text-right text-xs leading-tight', 'text-danger' => $overdue, 'text-brand-text' => ! $overdue])>
                        <span class="block font-semibold">{{ $item['date']->isToday() ? 'Today' : $item['date']->format('j M Y') }}</span>
                        <span class="text-ink-subtle">{{ $overdue ? 'overdue' : $item['date']->diffForHumans(['parts' => 1]) }}</span>
                    </span>
                </a>
            @empty
                <x-ui.empty-state icon="clock" title="No re-engagements scheduled" description="When you mark a lead lost, add a re-engage date and it will come back into your schedule." />
            @endforelse
        </x-ui.card>

        <div class="grid min-w-0 gap-4">
            <x-ui.card title="Why my properties said no">
                @if ($byObjection->isEmpty())
                    <x-ui.empty-state icon="chart" title="No objections recorded in this period" />
                @else
                    <dl class="grid gap-2">
                        @foreach ($byObjection as $value => $count)
                            <div class="grid grid-cols-[150px_1fr_64px] items-center gap-3 text-[13px]">
                                <dt class="truncate text-ink">{{ \App\Enums\Objection::from($value)->label() }}</dt>
                                <div class="h-2 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-brand" style="width: {{ round($count / $objectionMax * 100) }}%"></div></div>
                                <dd class="tabular text-right text-ink"><span class="font-semibold">{{ $count }}</span> <span class="text-xs text-ink-subtle">{{ round($count / $explainedTotal * 100) }}%</span></dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </x-ui.card>

            @if ($byCompetitor->isNotEmpty())
                <x-ui.card title="Who they chose instead">
                    <dl class="grid gap-2">
                        @foreach ($byCompetitor as $name => $count)
                            <div class="grid grid-cols-[130px_1fr_32px] items-center gap-3 text-[13px]">
                                <dt class="truncate text-ink">{{ $name }}</dt>
                                <div class="h-2 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-danger/60" style="width: {{ round($count / $competitorMax * 100) }}%"></div></div>
                                <dd class="tabular text-right font-semibold text-ink">{{ $count }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-ui.card>
            @endif
        </div>
    </div>

    <x-ui.table-card>
        <div class="border-b border-line px-4 py-2.5"><h2 class="text-sm font-semibold text-ink">My lost properties</h2></div>
        <table class="w-full min-w-[860px] text-sm">
            <thead class="text-left uppercase"><tr><th class="text-left">Lost</th><th class="text-left">Property</th><th class="text-left">Objection</th><th class="text-left">Competitor</th><th class="text-left">Notes</th></tr></thead>
            <tbody class="divide-y divide-line">
                @forelse ($losses as $loss)
                    <tr>
                        <td class="tabular text-ink-muted">{{ $loss['date']?->format('j M Y') }}</td>
                        <td><a href="{{ $loss['url'] }}" wire:navigate class="grid leading-tight"><span class="font-medium text-ink hover:text-brand-text">{{ $loss['name'] }}</span><span class="text-xs text-ink-subtle">{{ $loss['type'] }} · {{ $loss['kind'] === 'registry' ? 'Registry property you represent' : 'Your lead' }}</span></a></td>
                        <td>@if ($loss['objection'])<x-ui.pill tone="danger" :dot="false">{{ $loss['objection']->label() }}</x-ui.pill>@else<span class="text-xs font-medium text-warning">No reason recorded</span>@endif</td>
                        <td class="text-ink-muted">{{ $loss['competitor'] ?? '—' }}</td>
                        <td class="max-w-80 text-ink-muted"><span class="line-clamp-2 text-xs">{{ $loss['notes'] ?? '—' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty-state icon="check-circle" title="No lost properties in this period" description="Nice. When a property says no, mark the lead lost with the reason so it shows here." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>
</div>
