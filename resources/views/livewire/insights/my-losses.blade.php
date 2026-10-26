@php
    $objectionMax = max(1, (int) $byObjection->max());
    $competitorMax = max(1, (int) $byCompetitor->max());
    $explainedTotal = max(1, (int) $byObjection->sum());
    $isDue = fn ($item): bool => $item['date']->isPast() || $item['date']->isToday();
    $dueNow = $upcoming->filter($isDue)->values();
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="My losses" description="Properties that said no to you, why, who they chose instead, and when to try again. Only you and your managers see this." />

    <p class="text-sm text-ink-muted">
        @if ($losses->isEmpty())
            <span class="font-semibold text-success">No lost properties in this period.</span>
        @else
            You lost {{ number_format($losses->count()) }} {{ \Illuminate\Support\Str::plural('property', $losses->count()) }}@if ($topObjection), mostly because of “{{ $topObjection->label() }}”@endif.
            @if ($dueNow->isNotEmpty())
                <span class="font-semibold text-warning">{{ $dueNow->count() }} {{ $dueNow->count() === 1 ? 'is' : 'are' }} ready to approach again.</span>
            @endif
        @endif
    </p>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-4" wire:loading.delay.class="opacity-60">
        <x-ui.kpi size="lg" label="Properties lost" :value="number_format($losses->count())" />
        <x-ui.kpi size="lg" label="My most common objection" :value="$topObjection?->label() ?? '—'" />
        <x-ui.kpi size="lg" label="Lost to competitors" :value="$competitorShare.'%'" />
        <x-ui.kpi size="lg" label="Re-engagements coming up" :value="$upcoming->count()" tone="text-brand-text" />
    </dl>

    <div class="max-w-full overflow-x-auto">
        <x-ui.segmented wire:model.live="period" :options="\App\Livewire\Insights\Objections::Periods" />
    </div>

    <div class="grid gap-5" wire:loading.delay.class="opacity-60">
        @if ($dueNow->isNotEmpty())
            <x-ui.card title="Needs attention" description="Due today or overdue. Time to try these again." :padding="false">
                @foreach ($dueNow->take(5) as $item)
                    <a href="{{ $item['url'] }}" wire:navigate wire:key="loss-due-{{ $loop->index }}" class="flex min-h-12 items-center justify-between gap-3 border-b border-line px-4 py-2 last:border-b-0 hover:bg-surface-muted/50">
                        <span class="truncate text-sm font-semibold text-ink">{{ $item['name'] }}</span>
                        <span class="shrink-0 text-xs font-semibold {{ $item['date']->isToday() ? 'text-brand-text' : 'text-danger' }}">{{ $item['date']->isToday() ? 'Today' : 'Overdue since '.$item['date']->format('j M') }}</span>
                    </a>
                @endforeach
            </x-ui.card>
        @endif

        @if ($unexplained)
            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-warning/40 bg-warning-soft/50 px-4 py-2.5 text-sm text-ink">
                <x-ui.icon name="alert" class="size-4 text-warning" />
                {{ $unexplained }} lost {{ \Illuminate\Support\Str::plural('lead', $unexplained) }} {{ $unexplained === 1 ? 'has' : 'have' }} no objection recorded (marked lost before objections were tracked). They show as “No reason recorded” below.
            </div>
        @endif

        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            {{-- Upcoming re-engagements first: that's the salesperson's action list. --}}
            <x-ui.card title="Upcoming re-engagements" description="Lost properties due to be approached again in the next 120 days" :padding="false">
                @forelse ($upcoming as $item)
                    <a href="{{ $item['url'] }}" wire:navigate class="flex min-h-12 items-center justify-between gap-3 border-b border-line px-4 py-2.5 last:border-b-0 hover:bg-surface-muted/50">
                        <span class="grid min-w-0 leading-tight">
                            <span class="truncate text-sm font-semibold text-ink">{{ $item['name'] }}</span>
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
                        <dl class="grid gap-3">
                            @foreach ($byObjection as $value => $count)
                                @php
                                    $barWidth = (int) round($count / $objectionMax * 100);
                                    $share = (int) round($count / $explainedTotal * 100);
                                @endphp
                                <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 text-sm sm:grid-cols-[150px_1fr_72px]">
                                    <dt class="order-1 truncate text-ink">{{ \App\Enums\Objection::from($value)->label() }}</dt>
                                    <div class="order-3 col-span-2 h-2 overflow-hidden rounded-full bg-surface-muted sm:order-2 sm:col-span-1" role="progressbar" aria-label="{{ \App\Enums\Objection::from($value)->label() }}" aria-valuenow="{{ $barWidth }}" aria-valuemin="0" aria-valuemax="100" aria-valuetext="{{ $count }} lost, {{ $share }}%"><div class="h-full rounded-full bg-brand" style="width: {{ $barWidth }}%"></div></div>
                                    <dd class="tabular order-2 text-right text-ink sm:order-3"><span class="font-semibold">{{ $count }}</span> <span class="text-xs text-ink-subtle">{{ $share }}%</span></dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </x-ui.card>

                @if ($byCompetitor->isNotEmpty())
                    <x-ui.card title="Who they chose instead">
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
                    </x-ui.card>
                @endif
            </div>
        </div>

        <x-ui.card title="My lost properties" :padding="false">
            {{-- Phones: one card per loss --}}
            <ul class="divide-y divide-line sm:hidden">
                @forelse ($losses as $loss)
                    <li class="grid gap-1.5 px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <a href="{{ $loss['url'] }}" wire:navigate class="grid min-h-10 min-w-0 content-center leading-tight">
                                <span class="font-semibold text-ink">{{ $loss['name'] }}</span>
                                <span class="text-xs text-ink-subtle">{{ $loss['type'] }} · {{ $loss['kind'] === 'registry' ? 'Registry property you represent' : 'Your lead' }}</span>
                            </a>
                            <span class="tabular shrink-0 text-xs text-ink-muted">{{ $loss['date']?->format('j M Y') }}</span>
                        </div>
                        <div>@if ($loss['objection'])<x-ui.pill tone="danger" :dot="false">{{ $loss['objection']->label() }}</x-ui.pill>@else<span class="text-xs font-medium text-warning">No reason recorded</span>@endif</div>
                        @if ($loss['competitor'] ?? null)<p class="text-xs text-ink-muted">Chose: {{ $loss['competitor'] }}</p>@endif
                        @if ($loss['notes'] ?? null)<p class="line-clamp-3 text-xs text-ink-muted">{{ $loss['notes'] }}</p>@endif
                    </li>
                @empty
                    <li><x-ui.empty-state icon="check-circle" title="No lost properties in this period" description="Nice. When a property says no, mark the lead lost with the reason so it shows here." /></li>
                @endforelse
            </ul>

            {{-- Tablets and up: the table --}}
            <x-ui.table-card :sticky="false" class="hidden rounded-none border-0 shadow-none sm:block">
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
        </x-ui.card>
    </div>
</div>
