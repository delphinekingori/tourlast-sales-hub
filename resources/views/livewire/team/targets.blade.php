@php
    $withTarget = $rows->filter(fn ($row) => $row['target']);
    $combinedTarget = (int) $withTarget->sum(fn ($row) => $row['target']->target);
    $combinedOnboarded = (int) $rows->sum('onboarded');
    $overallPct = $combinedTarget > 0 ? min(100, (int) round($withTarget->sum('onboarded') / $combinedTarget * 100)) : null;
    $noTarget = $rows->filter(fn ($row) => ! $row['target'])->values();
@endphp

<div class="grid gap-5">
    <x-ui.page-header eyebrow="Management" title="Targets" description="Each salesperson sets their own monthly target. Managers can see targets but not change them." />

    <p class="text-sm text-ink-muted">
        @if ($rows->isEmpty())
            No salespeople to show yet.
        @elseif ($missing)
            <span class="font-semibold text-warning">{{ $missing }} of {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('salesperson', $rows->count()) }} {{ $missing === 1 ? 'has' : 'have' }} no target for {{ $monthDate->format('F Y') }}.</span>
        @else
            <span class="font-semibold text-success">Everyone has a target for {{ $monthDate->format('F Y') }}.</span>
        @endif
        {{ $locked ? 'Targets are locked.' : 'Targets can still be changed.' }}
    </p>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-4" wire:loading.delay.class="opacity-60">
        <x-ui.kpi size="lg" label="Combined target" :value="number_format($combinedTarget)" hint="Partners to onboard" />
        <x-ui.kpi size="lg" label="Onboarded so far" :value="number_format($combinedOnboarded)" :hint="$monthDate->format('F Y')" />
        <x-ui.kpi size="lg" label="Of combined target" :value="$overallPct !== null ? $overallPct.'%' : '—'" :hint="$overallPct !== null ? 'People with a target only' : 'No targets set'" />
        <x-ui.kpi size="lg" label="Without a target" :value="$missing" :tone="$missing ? 'text-warning' : 'text-success'" />
    </dl>

    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
        <label for="targets-month" class="text-sm font-medium text-ink-muted">Month</label>
        <select wire:model.live="month" id="targets-month" aria-label="Month" class="h-10 w-full rounded-md border border-line-strong bg-surface px-3 text-sm font-medium text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none sm:w-auto">
            @foreach ($monthOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-surface px-5 py-4 text-sm">
        <x-ui.icon name="lock" class="size-5 text-brand-text" />
        <span class="text-ink-muted">
            @if ($locked) Targets for {{ $monthDate->format('F Y') }} are locked (since {{ $lockDate->format('j M') }}). @else Targets for {{ $monthDate->format('F Y') }} can be changed until {{ $lockDate->format('j M') }}. @endif
        </span>
        @if ($missing)
            <x-ui.pill tone="warning" class="ml-auto">{{ $missing }} without a target</x-ui.pill>
        @endif
    </div>

    <div class="grid gap-5" wire:loading.delay.class="opacity-60">
        @if ($noTarget->isNotEmpty())
            <x-ui.card title="Needs attention" description="No target set for this month. Ask them to set one.">
                <ul class="-my-1 divide-y divide-line">
                    @foreach ($noTarget as $row)
                        <li wire:key="target-attn-{{ $row['user']->id }}">
                            <a href="{{ route('team.member', $row['user']) }}" wire:navigate class="flex min-h-11 items-center gap-2.5 py-2">
                                <x-ui.avatar :user="$row['user']" size="sm" />
                                <span class="truncate text-sm font-semibold text-ink hover:text-brand-text">{{ $row['user']->name }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        {{-- Phones: one card per person --}}
        <ul class="grid gap-3 sm:hidden">
            @foreach ($rows as $row)
                <li wire:key="target-card-{{ $row['user']->id }}" class="grid gap-2.5 rounded-xl border border-line bg-surface p-4 shadow-card">
                    <div class="flex items-center justify-between gap-3">
                        <span class="flex min-w-0 items-center gap-2.5"><x-ui.avatar :user="$row['user']" size="sm" /><span class="truncate font-semibold text-ink">{{ $row['user']->name }}</span></span>
                        @unless ($row['target'])
                            <x-ui.pill tone="warning">No target</x-ui.pill>
                        @endunless
                    </div>
                    @if ($row['target'])
                        @php($pct = min(100, round($row['onboarded'] / max(1, $row['target']->target) * 100)))
                        <div class="grid gap-1">
                            <div class="flex items-baseline justify-between text-xs text-ink-muted">
                                <span>{{ $row['onboarded'] }} onboarded of {{ $row['target']->target }}</span>
                                <span class="tabular font-semibold text-ink">{{ $pct }}%</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-surface-muted" role="progressbar" aria-label="Progress to target for {{ $row['user']->name }}" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-brand" style="width: {{ $pct }}%"></div></div>
                            <p class="text-xs text-ink-subtle">Set {{ $row['target']->updated_at->format('j M Y') }}</p>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        {{-- Tablets and up: the table --}}
        <x-ui.table-card class="hidden sm:block">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="text-left text-ink-subtle uppercase">
                    <tr>
                        <th class="text-left">Salesperson</th>
                        <th class="text-right">Target</th>
                        <th class="text-right">Onboarded</th>
                        <th class="text-left">Progress</th>
                        <th class="text-left">Set</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($rows as $row)
                        <tr wire:key="target-{{ $row['user']->id }}">
                            <td>
                                <div class="flex items-center gap-3">
                                    <x-ui.avatar :user="$row['user']" size="sm" />
                                    <span class="font-semibold text-ink">{{ $row['user']->name }}</span>
                                </div>
                            </td>
                            <td class="tabular text-right font-bold text-ink">{{ $row['target']?->target ?? '—' }}</td>
                            <td class="tabular text-right text-ink-muted">{{ $row['onboarded'] }}</td>
                            <td>
                                @if ($row['target'])
                                    @php($pct = min(100, round($row['onboarded'] / max(1, $row['target']->target) * 100)))
                                    <div class="flex items-center gap-3">
                                        <div class="h-1.5 w-28 overflow-hidden rounded-full bg-surface-muted" role="progressbar" aria-label="Progress to target for {{ $row['user']->name }}" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-brand" style="width: {{ $pct }}%"></div></div>
                                        <span class="tabular text-xs text-ink-muted">{{ $pct }}%</span>
                                    </div>
                                @else
                                    <x-ui.pill tone="warning">No target</x-ui.pill>
                                @endif
                            </td>
                            <td class="text-xs text-ink-subtle">{{ $row['target']?->updated_at->format('j M Y') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.table-card>
    </div>
</div>
