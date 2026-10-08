{{--
    Column chart over time, one or two stacked series.
    $points: list of rows, each with 'label' and a value under every series key.
    $series: list of ['key' => row key, 'name' => legend name, 'slot' => 1|2 (colour --series-N)].
    Every value is also in the "Show as table" view; the tooltip only adds to it.
--}}
@props(['points' => [], 'series' => [], 'money' => false, 'height' => 180, 'label' => 'Chart', 'empty' => 'Nothing in this period.'])

@php
    $format = fn (float $value): string => ($money ? 'KES ' : '').number_format($value);
    $fill = fn (int $slot): string => $slot === 2 ? 'bg-series-2' : 'bg-series-1';
    $compact = fn (float $value): string => match (true) {
        $value >= 1_000_000 => rtrim(rtrim(number_format($value / 1_000_000, 1), '0'), '.').'M',
        $value >= 1_000 => rtrim(rtrim(number_format($value / 1_000, 1), '0'), '.').'K',
        default => rtrim(rtrim(number_format($value, 1), '0'), '.'),
    };
    $totals = array_map(fn (array $point): float => array_sum(array_map(fn (array $s): float => (float) ($point[$s['key']] ?? 0), $series)), $points);
    $max = $totals === [] ? 0 : max($totals);

    // A clean axis: about four steps of 1, 2, 2.5 or 5 × a power of ten.
    $step = 1;
    if ($max > 0) {
        $raw = $max / 4;
        $magnitude = 10 ** floor(log10($raw));
        $step = (fn ($n) => match (true) { $n <= 1 => 1, $n <= 2 => 2, $n <= 2.5 => 2.5, $n <= 5 => 5, default => 10 })($raw / $magnitude) * $magnitude;
    }
    $top = $max > 0 ? ceil($max / $step) * $step : 1;
    $ticks = $max > 0 ? range(0, $top, $step) : [0];
    $count = count($points);
    $labelEvery = max(1, (int) ceil($count / 8));
@endphp

<figure {{ $attributes->merge(['class' => 'grid gap-3']) }}>
    @if ($max <= 0)
        <p class="py-6 text-center text-[13px] text-ink-muted">{{ $empty }}</p>
    @else
        @if (count($series) > 1)
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-muted" aria-hidden="true">
                @foreach ($series as $s)
                    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-[2px] {{ $fill($s['slot']) }}"></span>{{ $s['name'] }}</span>
                @endforeach
            </div>
        @endif

        <div class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-2" role="img" aria-label="{{ $label }}">
            {{-- Y axis --}}
            <div class="relative w-12" style="height: {{ $height }}px">
                @foreach ($ticks as $tick)
                    <span class="tabular absolute right-0 translate-y-1/2 text-[11px] leading-none text-ink-subtle" style="bottom: {{ $tick / $top * 100 }}%">{{ $compact((float) $tick) }}</span>
                @endforeach
            </div>

            {{-- Plot --}}
            <div class="relative" style="height: {{ $height }}px">
                @foreach ($ticks as $tick)
                    <span class="absolute inset-x-0 h-px bg-line" style="bottom: {{ $tick / $top * 100 }}%"></span>
                @endforeach

                <div class="absolute inset-0 flex items-end">
                    @foreach ($points as $index => $point)
                        <div tabindex="0" class="group relative flex h-full min-w-0 flex-1 items-end justify-center px-[2px] outline-none" aria-label="{{ $point['label'] }}: {{ collect($series)->map(fn ($s) => $s['name'].' '.$format((float) ($point[$s['key']] ?? 0)))->implode(', ') }}">
                            <span class="absolute inset-x-0 inset-y-0 rounded-sm group-hover:bg-surface-muted/60 group-focus-visible:bg-surface-muted/60"></span>
                            @if ($totals[$index] > 0)
                                <div class="relative flex w-full max-w-6 flex-col-reverse gap-[2px] overflow-hidden rounded-t-[4px]" style="height: {{ $totals[$index] / $top * 100 }}%">
                                    @foreach ($series as $s)
                                        @php($value = (float) ($point[$s['key']] ?? 0))
                                        @if ($value > 0)
                                            <span class="block min-h-px {{ $fill($s['slot']) }}" style="flex: {{ $value }} 1 0"></span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif

                            {{-- Tooltip --}}
                            <div @class([
                                'pointer-events-none absolute bottom-full z-20 mb-1 hidden w-max min-w-36 rounded-md border border-line bg-surface px-3 py-2 text-xs shadow-overlay group-hover:block group-focus-visible:block',
                                'left-0' => $index < $count / 2,
                                'right-0' => $index >= $count / 2,
                            ])>
                                <p class="mb-1 font-medium text-ink-muted">{{ $point['label'] }}</p>
                                @foreach ($series as $s)
                                    <p class="flex items-center gap-2"><span class="h-0.5 w-2.5 rounded-full {{ $fill($s['slot']) }}"></span><span class="tabular font-bold text-ink">{{ $format((float) ($point[$s['key']] ?? 0)) }}</span><span class="text-ink-subtle">{{ $s['name'] }}</span></p>
                                @endforeach
                                @if (count($series) > 1)
                                    <p class="mt-1 border-t border-line pt-1 text-ink-muted">Total <span class="tabular font-bold text-ink">{{ $format($totals[$index]) }}</span></p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- X axis --}}
            <span></span>
            <div class="flex pt-1.5">
                @foreach ($points as $index => $point)
                    <span class="min-w-0 flex-1 truncate text-center text-[11px] text-ink-subtle">{{ $index % $labelEvery === 0 ? $point['label'] : '' }}</span>
                @endforeach
            </div>
        </div>

        <details class="text-[13px]">
            <summary class="cursor-pointer text-xs font-medium text-brand-text hover:underline">Show as table</summary>
            <div class="mt-2 max-h-64 overflow-auto rounded-md border border-line">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-ink-subtle uppercase">
                        <tr>
                            <th class="px-3 py-1.5 text-left">Period</th>
                            @foreach ($series as $s)<th class="px-3 py-1.5 text-right">{{ $s['name'] }}</th>@endforeach
                            @if (count($series) > 1)<th class="px-3 py-1.5 text-right">Total</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($points as $index => $point)
                            <tr>
                                <td class="px-3 py-1.5 text-ink">{{ $point['label'] }}</td>
                                @foreach ($series as $s)<td class="tabular px-3 py-1.5 text-right">{{ $format((float) ($point[$s['key']] ?? 0)) }}</td>@endforeach
                                @if (count($series) > 1)<td class="tabular px-3 py-1.5 text-right font-bold text-ink">{{ $format($totals[$index]) }}</td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</figure>
