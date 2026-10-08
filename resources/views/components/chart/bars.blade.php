{{--
    Horizontal bar chart for ranking categories, one or two stacked series.
    $rows: list of ['label' => string, 'values' => [series key => number]].
    $series: list of ['key' => values key, 'name' => legend name, 'slot' => 1|2 (colour --series-N)].
    Shows the first $limit rows, largest first; the full list stays in the table it sits beside.
--}}
@props(['rows' => [], 'series' => [], 'money' => false, 'limit' => 10, 'label' => 'Chart'])

@php
    $format = fn (float $value): string => ($money ? 'KES ' : '').number_format($value);
    $fill = fn (int $slot): string => $slot === 2 ? 'bg-series-2' : 'bg-series-1';
    $rows = collect($rows)
        ->map(fn (array $row): array => [...$row, 'total' => array_sum(array_map(fn (array $s): float => (float) ($row['values'][$s['key']] ?? 0), $series))])
        ->filter(fn (array $row): bool => $row['total'] > 0)
        ->sortByDesc('total')->values();
    $hidden = max(0, $rows->count() - $limit);
    $rows = $rows->take($limit);
    $max = (float) $rows->max('total');
@endphp

@if ($rows->isNotEmpty())
    <figure {{ $attributes->merge(['class' => 'grid gap-2']) }} aria-label="{{ $label }}">
        @if (count($series) > 1)
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-muted" aria-hidden="true">
                @foreach ($series as $s)
                    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-[2px] {{ $fill($s['slot']) }}"></span>{{ $s['name'] }}</span>
                @endforeach
            </div>
        @endif

        <ul class="grid gap-1">
            @foreach ($rows as $index => $row)
                <li tabindex="0" class="group relative grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)_auto] items-center gap-3 rounded-sm py-1 outline-none hover:bg-surface-muted/60 focus-visible:bg-surface-muted/60 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)_auto]"
                    aria-label="{{ $row['label'] }}: {{ collect($series)->map(fn ($s) => $s['name'].' '.$format((float) ($row['values'][$s['key']] ?? 0)))->implode(', ') }}">
                    <span class="truncate text-[13px] text-ink" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                    <span class="flex h-4 min-w-0 items-center">
                        <span class="flex h-full gap-[2px] overflow-hidden rounded-r-[4px]" style="width: {{ max(1, $row['total'] / max(1, $max) * 100) }}%">
                            @foreach ($series as $s)
                                @php($value = (float) ($row['values'][$s['key']] ?? 0))
                                @if ($value > 0)
                                    <span class="block h-full min-w-px {{ $fill($s['slot']) }}" style="flex: {{ $value }} 1 0"></span>
                                @endif
                            @endforeach
                        </span>
                    </span>
                    <span class="tabular text-right text-xs font-medium text-ink">{{ $format($row['total']) }}</span>

                    @if (count($series) > 1)
                        <div @class([
                            'pointer-events-none absolute left-1/3 z-20 hidden w-max min-w-36 rounded-md border border-line bg-surface px-3 py-2 text-xs shadow-overlay group-hover:block group-focus-visible:block',
                            'top-full mt-1' => $index < 3,
                            'bottom-full mb-1' => $index >= 3,
                        ])>
                            <p class="mb-1 font-medium text-ink-muted">{{ $row['label'] }}</p>
                            @foreach ($series as $s)
                                <p class="flex items-center gap-2"><span class="h-0.5 w-2.5 rounded-full {{ $fill($s['slot']) }}"></span><span class="tabular font-bold text-ink">{{ $format((float) ($row['values'][$s['key']] ?? 0)) }}</span><span class="text-ink-subtle">{{ $s['name'] }}</span></p>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($hidden > 0)
            <p class="text-xs text-ink-subtle">Top {{ $limit }} shown; {{ $hidden }} more in the table.</p>
        @endif
    </figure>
@endif
