@php
    $figures = match ($tab) {
        'flights' => $report->flightSummary(),
        'tours' => $report->tourSummary(),
        'providers' => $report->providerSummary(),
        'approvals' => $report->approvalSummary(),
        default => [],
    };

    // A label like "Revenue (USD)" keeps its wording; the value gets the code so money is never bare.
    $tiles = collect($figures)->map(function ($value, $label): array {
        $currency = preg_match('/\((USD|KES)\)\s*$/', (string) $label, $match) ? $match[1] : null;
        $shown = is_numeric($value) ? number_format((float) $value) : $value;

        return [
            'label' => $label,
            'value' => $currency ? $currency.' '.$shown : $shown,
            'raw' => $value,
        ];
    })->values();

    $headline = $tiles->count() > 5 ? $tiles->take(4) : $tiles;
    $more = $tiles->count() > 5 ? $tiles->slice(4) : collect();

    $gridColumns = match ($headline->count()) {
        3 => 'md:grid-cols-3',
        5 => 'md:grid-cols-5',
        default => 'md:grid-cols-4',
    };

    $tileTone = function (string $label, $raw): string {
        if (! is_numeric($raw) || (float) $raw <= 0) {
            return 'text-ink';
        }

        return in_array($label, ['Contracts expiring soon', 'Contracts expired', 'Pending now'], true) ? 'text-warning' : 'text-ink';
    };

    $tileLink = function (string $label) use ($everyone): ?string {
        return match ($label) {
            'Contracts active', 'Contracts expiring soon', 'Contracts expired' => route('travel.contracts.index'),
            'Pending now' => $everyone ? route('travel.approvals.index') : null,
            default => null,
        };
    };

    $summary = match ($tab) {
        'flights' => 'Flights: '.number_format((float) ($figures['Bookings'] ?? 0)).' bookings worth USD '.number_format((float) ($figures['Revenue (USD)'] ?? 0)).'.',
        'tours' => 'Tours: '.number_format((float) ($figures['Bookings'] ?? 0)).' bookings worth KES '.number_format((float) ($figures['Revenue (KES)'] ?? 0)).'.',
        'providers' => number_format((float) ($figures['Active providers'] ?? 0)).' active providers.',
        'approvals' => number_format((float) ($figures['Pending now'] ?? 0)).' packages waiting for a decision.',
        default => 'Flights are in USD and tours in KES, so the two are shown side by side.',
    };

    $attention = [];

    if (($figures['Contracts expiring soon'] ?? 0) > 0) {
        $attention[] = ['text' => number_format($figures['Contracts expiring soon']).' '.\Illuminate\Support\Str::plural('contract', $figures['Contracts expiring soon']).' expiring soon', 'href' => route('travel.contracts.index')];
    }

    if (($figures['Contracts expired'] ?? 0) > 0) {
        $attention[] = ['text' => number_format($figures['Contracts expired']).' expired '.\Illuminate\Support\Str::plural('contract', $figures['Contracts expired']), 'href' => route('travel.contracts.index')];
    }

    if (($figures['Pending now'] ?? 0) > 0) {
        $attention[] = ['text' => number_format($figures['Pending now']).' '.\Illuminate\Support\Str::plural('package', $figures['Pending now']).' waiting for review', 'href' => $everyone ? route('travel.approvals.index') : null];
    }

    $flightsSeries = ['key' => 'flight_revenue', 'name' => 'Flights', 'slot' => 1, 'currency' => 'USD'];
    $toursSeries = ['key' => 'tour_revenue', 'name' => 'Tours & experiences', 'slot' => 2, 'currency' => 'KES'];
    $trendSeries = match ($tab) {
        'flights' => [$flightsSeries],
        'tours' => [$toursSeries],
        'salespeople' => [$flightsSeries, $toursSeries],
        default => null,
    };
    $fieldClass = 'h-10 w-full rounded-md border border-line-strong bg-surface px-3 text-sm text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none sm:w-auto';
@endphp

<div class="grid gap-5">
    <x-ui.page-header eyebrow="Travel Sales" title="Travel reports" :description="($everyone ? 'All travel salespeople' : 'Your sales').' · '.$rangeLabel">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow-right" :href="route('travel.reports.export', $exportQuery)">Export Excel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="max-w-full overflow-x-auto">
        <x-ui.segmented wire:model.live="tab" :options="\App\Livewire\Travel\Reports::Tabs" />
    </div>

    <p class="text-sm text-ink-muted">{{ $summary }}</p>

    <div class="grid gap-5" wire:loading.delay.class="opacity-60">
        @if ($headline->isNotEmpty())
            <div class="grid gap-2">
                <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card max-md:[&>*:last-child:nth-child(odd)]:col-span-2 {{ $gridColumns }}">
                    @foreach ($headline as $tile)
                        <x-ui.kpi size="lg" :label="$tile['label']" :value="$tile['value']" :tone="$tileTone($tile['label'], $tile['raw'])" :href="$tileLink($tile['label'])" />
                    @endforeach
                </dl>
                @if ($more->isNotEmpty())
                    <details class="group rounded-xl border border-line bg-surface shadow-card">
                        <summary class="flex min-h-10 cursor-pointer items-center justify-between gap-2 px-4 text-sm font-medium text-ink-muted hover:text-ink">More numbers <span class="text-xs text-ink-subtle group-open:hidden">Show</span><span class="hidden text-xs text-ink-subtle group-open:inline">Hide</span></summary>
                        <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-b-xl border-t border-line bg-line max-md:[&>*:last-child:nth-child(odd)]:col-span-2 md:grid-cols-3">
                            @foreach ($more as $tile)
                                <x-ui.kpi :label="$tile['label']" :value="$tile['value']" />
                            @endforeach
                        </dl>
                    </details>
                @endif
            </div>
        @endif

        @if ($attention !== [])
            <x-ui.card title="Needs attention">
                <ul class="-my-1 divide-y divide-line">
                    @foreach ($attention as $item)
                        <li>
                            @if ($item['href'])
                                <a href="{{ $item['href'] }}" wire:navigate class="flex min-h-11 items-center justify-between gap-3 py-2 text-sm font-medium text-ink hover:text-brand-text">{{ $item['text'] }} <x-ui.icon name="arrow-right" class="size-4 shrink-0 text-ink-subtle" /></a>
                            @else
                                <p class="flex min-h-11 items-center py-2 text-sm font-medium text-ink">{{ $item['text'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        <div class="grid gap-2 rounded-xl border border-line bg-surface p-3 shadow-card sm:flex sm:flex-wrap sm:items-center">
            <select wire:model.live="period" aria-label="Period" class="{{ $fieldClass }}">
                @foreach (\App\Livewire\Travel\Reports::Periods as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @if ($period === 'custom')
                <input type="date" wire:model.live="from" aria-label="From" class="{{ $fieldClass }}" />
                <input type="date" wire:model.live="to" aria-label="To" class="{{ $fieldClass }}" />
            @endif
            @if ($everyone)
                <select wire:model.live="salesperson" aria-label="Salesperson" class="{{ $fieldClass }}">
                    <option value="">All travel salespeople</option>
                    @foreach ($salespeople as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        @if ($trendSeries)
            <x-ui.card :title="match ($tab) { 'flights' => 'Flight revenue', 'tours' => 'Tour revenue', default => 'Travel sales' }" :description="'Bookings that count as sales, by booking date · '.$rangeLabel">
                <x-chart.columns :points="$report->trend()" :series="$trendSeries" money :label="'Revenue over '.$rangeLabel" empty="No sales in this period." />
            </x-ui.card>
        @endif

        @if ($tab === 'flights')
            <div class="grid items-start gap-4 xl:grid-cols-2">
                @include('livewire.travel.partials.report-table', ['title' => 'Top routes', 'rows' => $report->routes(), 'chart' => ['series' => ['Bookings' => ['name' => 'Bookings', 'slot' => 1]]]])
                @include('livewire.travel.partials.report-table', ['title' => 'Airlines', 'rows' => $report->airlines(), 'chart' => ['series' => ['Revenue (USD)' => ['name' => 'Revenue', 'slot' => 1, 'currency' => 'USD']], 'money' => true]])
            </div>
            @include('livewire.travel.partials.report-table', ['title' => 'Refunds by status', 'description' => 'Statuses as Tourlast Flights Super Admin reports them', 'rows' => $report->flightRefunds(), 'chart' => ['series' => ['Amount (USD)' => ['name' => 'Amount', 'slot' => 1, 'currency' => 'USD']], 'money' => true]])
        @elseif ($tab === 'tours')
            @include('livewire.travel.partials.report-table', ['title' => 'Package performance', 'description' => 'Revenue from confirmed and completed bookings', 'rows' => $report->packagePerformance(), 'chart' => ['series' => ['Revenue (KES)' => ['name' => 'Revenue', 'slot' => 2]], 'money' => true]])
            @include('livewire.travel.partials.report-table', ['title' => 'Destinations', 'rows' => $report->destinations(), 'chart' => ['series' => ['Revenue (KES)' => ['name' => 'Revenue', 'slot' => 2]], 'money' => true]])
        @elseif ($tab === 'providers')
            @include('livewire.travel.partials.report-table', ['title' => 'Provider performance', 'description' => 'Bookings and revenue in the period; contracts as of today', 'rows' => $report->providerPerformance(), 'chart' => ['series' => ['Revenue (KES)' => ['name' => 'Revenue', 'slot' => 2]], 'money' => true]])
        @elseif ($tab === 'approvals')
            <x-ui.card title="Review decisions" description="Decisions made in the period">
                <x-chart.bars label="Review decisions" :series="[['key' => 'count', 'name' => 'Decisions', 'slot' => 1]]" :rows="[
                    ['label' => 'Approved (final)', 'values' => ['count' => $figures['Approved']]],
                    ['label' => 'Changes requested', 'values' => ['count' => $figures['Changes requested']]],
                    ['label' => 'Rejected', 'values' => ['count' => $figures['Rejected']]],
                ]" />
                @if ($figures['Approved'] + $figures['Changes requested'] + $figures['Rejected'] === 0)
                    <p class="text-sm text-ink-muted">No review decisions in this period.</p>
                @endif
            </x-ui.card>
        @elseif ($tab === 'salespeople')
            @include('livewire.travel.partials.report-table', ['title' => 'Travel salespeople', 'description' => 'Targets show when the period is one whole month. Flights are in USD and tours in KES.', 'rows' => $report->salespeople(), 'chart' => ['series' => ['Flight revenue (USD)' => ['name' => 'Flights', 'slot' => 1, 'currency' => 'USD'], 'Tour revenue (KES)' => ['name' => 'Tours & experiences', 'slot' => 2]], 'money' => true]])
        @endif
    </div>
</div>
