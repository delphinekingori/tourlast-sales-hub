<div class="grid gap-5">
    <x-ui.page-header eyebrow="Management" title="Targets" description="Each salesperson sets their own monthly target. Managers can see targets but not change them.">
        <x-slot:actions>
            <select wire:model.live="month" id="targets-month" aria-label="Month" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] font-medium text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                @foreach ($monthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-surface px-5 py-4 text-sm">
        <x-ui.icon name="lock" class="size-5 text-brand-text" />
        <span class="text-ink-muted">
            @if ($locked) Targets for {{ $monthDate->format('F Y') }} are locked (since {{ $lockDate->format('j M') }}). @else Targets for {{ $monthDate->format('F Y') }} can be changed until {{ $lockDate->format('j M') }}. @endif
        </span>
        @if ($missing)
            <x-ui.pill tone="warning" class="ml-auto">{{ $missing }} without a target</x-ui.pill>
        @endif
    </div>

    <x-ui.table-card>
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
                                    <div class="h-1.5 w-28 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-brand" style="width: {{ $pct }}%"></div></div>
                                    <span class="tabular text-[13px] text-ink-muted">{{ $pct }}%</span>
                                </div>
                            @else
                                <x-ui.pill tone="warning">No target</x-ui.pill>
                            @endif
                        </td>
                        <td class="text-[13px] text-ink-subtle">{{ $row['target']?->updated_at->format('j M Y') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.table-card>
</div>
