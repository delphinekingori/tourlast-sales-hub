@php
    $points = fn ($value): string => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
    $attention = $rows->filter(fn ($row) => in_array($row['status']['tone'], ['danger', 'warning'], true))->values();
    $selectClass = 'h-10 w-full rounded-md border border-line-strong bg-surface px-3 text-sm font-medium text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none sm:w-auto';
@endphp

<div class="grid gap-5">
    <x-ui.page-header eyebrow="Management" title="Team Performance" :description="'Points (Schedule 1) against each salesperson\'s own target · '.$range->label()" />

    <p class="text-sm text-ink-muted">
        @if ($rows->isEmpty())
            No salespeople to show yet.
        @elseif ($totals['needsAttention'] === 0)
            <span class="font-semibold text-success">Everyone is on track.</span>
            The team has {{ $points($totals['points']) }} {{ $totals['target'] ? 'of '.$totals['target'].' target ' : '' }}points so far.
        @else
            <span class="font-semibold text-warning">{{ $totals['needsAttention'] }} of {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('salesperson', $rows->count()) }} {{ $totals['needsAttention'] === 1 ? 'needs' : 'need' }} a check-in.</span>
            The team has {{ $points($totals['points']) }} {{ $totals['target'] ? 'of '.$totals['target'].' target ' : '' }}points so far.
        @endif
    </p>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-4" wire:loading.delay.class="opacity-60">
        <x-ui.kpi size="lg" label="Team points" :value="$points($totals['points'])" :hint="($totals['target'] ? 'of '.$totals['target'].' combined target · ' : '').$totals['onboarded'].' partners went live'" />
        <x-ui.kpi size="lg" label="Not live yet" :value="$totals['awaiting']" hint="Across the team" />
        <x-ui.kpi size="lg" label="Link clicks" :value="$totals['clicks']" :hint="$totals['clicks'] ? round($totals['onboarded'] / $totals['clicks'] * 100, 1).'% click → onboarded' : 'No clicks yet'" />
        <x-ui.kpi size="lg" label="Need attention" :value="$totals['needsAttention']" :tone="$totals['needsAttention'] ? 'text-warning' : 'text-success'" hint="Behind target or inactive" />
    </dl>

    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        @if ($regions->isNotEmpty())
            <select wire:model.live="region" id="perf-region" aria-label="Region" class="{{ $selectClass }}">
                <option value="">All regions</option>
                @foreach ($regions as $name)
                    <option value="{{ $name }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
        <div class="max-w-full overflow-x-auto">
            <x-ui.segmented wire:model.live="period" :options="\App\Support\Period::options()" />
        </div>
    </div>

    <div class="grid gap-5" wire:loading.delay.class="opacity-60">
        @if ($attention->isNotEmpty())
            <x-ui.card title="Needs attention" description="Behind target or inactive. Open a person to see why.">
                <ul class="-my-1 divide-y divide-line">
                    @foreach ($attention as $row)
                        <li wire:key="perf-attn-{{ $row['user']->id }}">
                            <a href="{{ route('team.member', $row['user']) }}" wire:navigate class="flex min-h-11 items-center justify-between gap-3 py-2">
                                <span class="flex min-w-0 items-center gap-2.5">
                                    <x-ui.avatar :user="$row['user']" size="sm" />
                                    <span class="truncate text-sm font-semibold text-ink hover:text-brand-text">{{ $row['user']->name }}</span>
                                </span>
                                <x-ui.pill :tone="$row['status']['tone']">{{ $row['status']['label'] }}</x-ui.pill>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        {{-- Phones: one card per person --}}
        <ul class="grid gap-3 sm:hidden">
            @forelse ($rows as $row)
                @php
                    $m = $row['metrics'];
                    $pct = $m['target'] ? min(100, (int) round($row['progress'] * 100)) : null;
                @endphp
                <li wire:key="perf-card-{{ $row['user']->id }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 shadow-card">
                    <div class="flex items-center justify-between gap-3">
                        <a href="{{ route('team.member', $row['user']) }}" wire:navigate class="flex min-h-10 min-w-0 items-center gap-2.5">
                            <x-ui.avatar :user="$row['user']" size="sm" presence />
                            <span class="grid min-w-0 leading-tight">
                                <span class="truncate font-semibold text-ink">{{ $row['user']->name }}</span>
                                <span class="truncate text-xs text-ink-subtle">{{ $row['user']->region ?? $row['user']->role()?->label() }}</span>
                            </span>
                        </a>
                        <x-ui.pill :tone="$row['status']['tone']">{{ $row['status']['label'] }}</x-ui.pill>
                    </div>
                    @if ($pct !== null)
                        <div class="grid gap-1">
                            <div class="flex items-baseline justify-between text-xs text-ink-muted">
                                <span>Own target</span>
                                <span class="tabular">{{ $points($m['points']) }} / {{ $m['target'] }} · {{ $pct }}%</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-surface-muted" role="progressbar" aria-label="Progress to own target for {{ $row['user']->name }}" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-brand" style="width: {{ $pct }}%"></div></div>
                        </div>
                    @else
                        <p class="text-xs text-ink-subtle">Own target: not set</p>
                    @endif
                    <dl class="grid grid-cols-3 gap-x-3 gap-y-2 text-xs">
                        <div><dt class="text-ink-subtle">Points</dt><dd class="tabular text-sm font-bold text-ink">{{ $points($m['points']) }}</dd></div>
                        <div><dt class="text-ink-subtle">Live</dt><dd class="tabular text-sm text-ink">{{ $m['onboarded'] }}</dd></div>
                        <div><dt class="text-ink-subtle">Not live</dt><dd class="tabular text-sm text-ink">{{ $m['awaiting'] }}@if ($m['stalled'])<span class="text-danger"> ({{ $m['stalled'] }} stalled)</span>@endif</dd></div>
                        <div><dt class="text-ink-subtle">Clicks</dt><dd class="tabular text-sm text-ink">{{ $m['clicks'] }}</dd></div>
                        <div><dt class="text-ink-subtle">Click → onboarded</dt><dd class="tabular text-sm text-ink">{{ $m['conversion'] !== null ? $m['conversion'].'%' : '—' }}</dd></div>
                        <div><dt class="text-ink-subtle">Activities</dt><dd class="tabular text-sm text-ink">{{ $m['activities'] }}</dd></div>
                    </dl>
                    @if ($canSeePay)
                        <p class="text-xs text-ink-subtle">Expected pay:
                            @if ($pay[$row['user']->id] !== null)<a href="{{ route('earnings.member', $row['user']) }}" wire:navigate class="inline-flex min-h-10 items-center font-semibold text-ink hover:text-brand-text">KES {{ number_format($pay[$row['user']->id]) }}</a>@else<span>No agreement</span>@endif
                        </p>
                    @endif
                </li>
            @empty
                <li><x-ui.empty-state icon="users" title="No salespeople yet" description="Invite salespeople from Users & Invites to see their performance here." /></li>
            @endforelse
        </ul>

        {{-- Tablets and up: the table --}}
        <x-ui.table-card class="hidden sm:block">
            <table class="w-full min-w-[900px] text-sm">
                <thead class="text-left text-ink-subtle uppercase">
                    <tr>
                        <th class="text-left">Salesperson</th>
                        <th class="text-left">Own target</th>
                        <th class="text-right">Points</th>
                        <th class="text-right">Live</th>
                        <th class="text-right">Not live</th>
                        <th class="text-right">Clicks</th>
                        <th class="text-right">Click → onboarded</th>
                        <th class="text-right">Activities</th>
                        @if ($canSeePay)<th class="text-right">Expected pay (KES)</th>@endif
                        <th class="text-left">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($rows as $row)
                        @php
                            $m = $row['metrics'];
                            $pct = $m['target'] ? min(100, (int) round($row['progress'] * 100)) : null;
                        @endphp
                        <tr wire:key="perf-{{ $row['user']->id }}" class="hover:bg-surface-muted/60">
                            <td>
                                <a href="{{ route('team.member', $row['user']) }}" wire:navigate class="flex min-h-10 items-center gap-3">
                                    <x-ui.avatar :user="$row['user']" size="sm" presence />
                                    <span class="grid leading-tight">
                                        <span class="font-semibold whitespace-nowrap text-ink hover:text-brand-text">{{ $row['user']->name }}</span>
                                        <span class="text-xs text-ink-subtle">{{ $row['user']->region ?? $row['user']->role()?->label() }}</span>
                                    </span>
                                </a>
                            </td>
                            <td>
                                @if ($pct !== null)
                                    <div class="flex items-center gap-3">
                                        <div class="h-1.5 w-20 overflow-hidden rounded-full bg-surface-muted" role="progressbar" aria-label="Progress to own target for {{ $row['user']->name }}" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-brand" style="width: {{ $pct }}%"></div></div>
                                        <span class="tabular whitespace-nowrap text-ink-muted">{{ $points($m['points']) }} / {{ $m['target'] }} · {{ $pct }}%</span>
                                    </div>
                                @else
                                    <span class="text-ink-subtle">Not set</span>
                                @endif
                            </td>
                            <td class="tabular text-right font-bold text-ink">{{ $points($m['points']) }}</td>
                            <td class="tabular text-right text-ink-muted">{{ $m['onboarded'] }}</td>
                            <td class="tabular text-right whitespace-nowrap text-ink-muted">{{ $m['awaiting'] }}@if ($m['stalled'])<span class="text-danger"> ({{ $m['stalled'] }} stalled)</span>@endif</td>
                            <td class="tabular text-right text-ink-muted">{{ $m['clicks'] }}</td>
                            <td class="tabular text-right text-ink-muted">{{ $m['conversion'] !== null ? $m['conversion'].'%' : '—' }}</td>
                            <td class="tabular text-right text-ink-muted">{{ $m['activities'] }}</td>
                            @if ($canSeePay)
                                <td class="tabular text-right whitespace-nowrap">
                                    @if ($pay[$row['user']->id] !== null)<a href="{{ route('earnings.member', $row['user']) }}" wire:navigate class="font-semibold text-ink hover:text-brand-text">{{ number_format($pay[$row['user']->id]) }}</a>@else<span class="text-ink-subtle">No agreement</span>@endif
                                </td>
                            @endif
                            <td><x-ui.pill :tone="$row['status']['tone']">{{ $row['status']['label'] }}</x-ui.pill></td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><x-ui.empty-state icon="users" title="No salespeople yet" description="Invite salespeople from Users & Invites to see their performance here." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.table-card>

        <p class="text-xs text-ink-subtle">
            Status: <b class="text-ink">Inactive</b> means no activity logged for {{ config('hub.inactive_after_days') }}+ days.
            <b class="text-ink">Behind</b> means below the pace needed to reach their own target by the end of the period.
        </p>
    </div>
</div>
