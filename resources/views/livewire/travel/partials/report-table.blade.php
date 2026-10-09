{{--
    Generic report table: $rows is a list of rows keyed by heading.
    Optional $chart draws bars above the table from the same rows (labelled by the first column):
    ['series' => [column heading => ['name' => legend name, 'slot' => 1|2]], 'money' => bool].
--}}
<x-ui.card :title="$title" :description="$description ?? null" :padding="false">
    @if ($rows === [])
        <p class="px-4 py-3 text-[13px] text-ink-muted">Nothing in this period.</p>
    @else
        @isset($chart)
            @php($labelColumn = array_key_first($rows[0]))
            <x-chart.bars class="border-b border-line px-4 py-3" :money="$chart['money'] ?? false" :label="$title"
                :series="collect($chart['series'])->map(fn ($s, $column) => ['key' => $column, 'name' => $s['name'], 'slot' => $s['slot']])->values()->all()"
                :rows="array_map(fn ($row) => ['label' => (string) $row[$labelColumn], 'values' => array_intersect_key($row, $chart['series'])], $rows)" />
        @endisset
        <x-ui.table-card :sticky="false" class="rounded-none border-0 shadow-none">
            <table class="w-full text-sm">
                <thead class="text-left uppercase">
                    <tr>
                        @foreach (array_keys($rows[0]) as $index => $heading)
                            <th @class(['text-left' => $index === 0 || ! is_numeric($rows[0][$heading]), 'text-right' => $index > 0 && is_numeric($rows[0][$heading])])>{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($rows as $row)
                        <tr>
                            @foreach ($row as $heading => $value)
                                @if ($loop->first)
                                    <td class="font-semibold text-ink">{{ $value }}</td>
                                @elseif (is_numeric($value))
                                    <td class="tabular text-right">{{ str_contains($heading, 'KES') ? number_format((float) $value) : number_format((float) $value) }}</td>
                                @else
                                    <td class="text-ink-muted">{{ $value === '' ? '—' : $value }}</td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.table-card>
    @endif
</x-ui.card>
