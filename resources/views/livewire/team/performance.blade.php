<div class="grid gap-5">
    <x-ui.page-header eyebrow="Management" title="Team Performance" :description="'Points (Schedule 1) against each salesperson\'s own target · '.$range->label()">
        <x-slot:actions>
            @if ($regions->isNotEmpty())
                <select wire:model.live="region" id="perf-region" aria-label="Region" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] font-medium text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                    <option value="">All regions</option>
                    @foreach ($regions as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif
            <x-ui.segmented wire:model.live="period" :options="\App\Support\Period::options()" />
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Team points" :value="rtrim(rtrim(number_format($totals['points'], 1), '0'), '.')" :hint="($totals['target'] ? 'of '.$totals['target'].' combined target · ' : '').$totals['onboarded'].' partners went live'" />
        <x-ui.stat label="Not live yet" :value="$totals['awaiting']" hint="Across the team" />
        <x-ui.stat label="Link clicks" :value="$totals['clicks']" :hint="$totals['clicks'] ? round($totals['onboarded'] / $totals['clicks'] * 100, 1).'% click → onboarded' : 'No clicks yet'" />
        <x-ui.stat label="Need attention" :value="$totals['needsAttention']" hint="Behind target or inactive" />
    </div>

    <x-ui.table-card>
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
                    @if ($canSeePay)<th class="text-right">Expected pay</th>@endif
                    <th class="text-left">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($rows as $row)
                    @php($m = $row['metrics'])
                    <tr wire:key="perf-{{ $row['user']->id }}" class="hover:bg-surface-muted/60">
                        <td>
                            <a href="{{ route('team.member', $row['user']) }}" wire:navigate class="flex items-center gap-3">
                                <x-ui.avatar :user="$row['user']" size="sm" presence />
                                <span class="grid leading-tight">
                                    <span class="font-semibold whitespace-nowrap text-ink hover:text-brand-text">{{ $row['user']->name }}</span>
                                    <span class="text-[13px] text-ink-subtle">{{ $row['user']->region ?? $row['user']->role()?->label() }}</span>
                                </span>
                            </a>
                        </td>
                        <td>
                            @if ($m['target'])
                                <div class="flex items-center gap-3">
                                    <div class="h-1.5 w-20 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-brand" style="width: {{ round($row['progress'] * 100) }}%"></div></div>
                                    <span class="tabular whitespace-nowrap text-ink-muted">{{ rtrim(rtrim(number_format($m['points'], 1), '0'), '.') }} / {{ $m['target'] }}</span>
                                </div>
                            @else
                                <span class="text-ink-subtle">Not set</span>
                            @endif
                        </td>
                        <td class="tabular text-right font-bold text-ink">{{ rtrim(rtrim(number_format($m['points'], 1), '0'), '.') }}</td>
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

    <p class="text-[13px] text-ink-subtle">
        Status: <b class="text-ink">Inactive</b> means no activity logged for {{ config('hub.inactive_after_days') }}+ days.
        <b class="text-ink">Behind</b> means below the pace needed to reach their own target by the end of the period.
    </p>
</div>
