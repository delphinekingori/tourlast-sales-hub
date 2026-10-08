@php
    $strip = function (array $figures): array {
        return collect($figures)->map(fn ($value, $label) => [$label, is_numeric($value) ? number_format((float) $value) : $value])->values()->all();
    };
@endphp

<div class="grid gap-5">
    <x-ui.page-header eyebrow="Travel Sales" title="Travel reports" :description="($everyone ? 'All travel salespeople' : 'Your sales').' · '.$rangeLabel">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow-right" :href="route('travel.reports.export', $exportQuery)">Export Excel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-wrap items-center gap-2 rounded-xl border border-line bg-surface p-2.5 shadow-card">
        <x-ui.segmented wire:model.live="tab" :options="\App\Livewire\Travel\Reports::Tabs" />
        <div class="ml-auto flex flex-wrap items-center gap-2">
            <select wire:model.live="period" aria-label="Period" class="h-8 rounded-md border border-line-strong bg-surface px-2.5 text-[13px] text-ink focus:border-brand focus:outline-none">
                @foreach (\App\Livewire\Travel\Reports::Periods as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @if ($period === 'custom')
                <input type="date" wire:model.live="from" aria-label="From" class="h-8 rounded-md border border-line-strong bg-surface px-2 text-[13px] text-ink" />
                <input type="date" wire:model.live="to" aria-label="To" class="h-8 rounded-md border border-line-strong bg-surface px-2 text-[13px] text-ink" />
            @endif
            @if ($everyone)
                <select wire:model.live="salesperson" aria-label="Salesperson" class="h-8 rounded-md border border-line-strong bg-surface px-2.5 text-[13px] text-ink focus:border-brand focus:outline-none">
                    <option value="">All travel salespeople</option>
                    @foreach ($salespeople as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    </div>

    <div class="grid gap-4" wire:loading.delay.class="opacity-60">
        @php
            $figures = match ($tab) {
                'flights' => $report->flightSummary(),
                'tours' => $report->tourSummary(),
                'providers' => $report->providerSummary(),
                'approvals' => $report->approvalSummary(),
                default => [],
            };
        @endphp

        @if ($figures !== [])
            <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-4 xl:grid-cols-{{ min(7, count($figures)) }}">
                @foreach ($strip($figures) as [$label, $value])
                    <div class="grid gap-0.5 bg-surface px-4 py-3">
                        <dt class="truncate text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                        <dd class="tabular truncate text-xl leading-tight font-bold text-ink">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @php
            $flightsSeries = ['key' => 'flight_revenue', 'name' => 'Flights', 'slot' => 1];
            $toursSeries = ['key' => 'tour_revenue', 'name' => 'Tours & experiences', 'slot' => 2];
            $trendSeries = match ($tab) {
                'flights' => [$flightsSeries],
                'tours' => [$toursSeries],
                'salespeople' => [$flightsSeries, $toursSeries],
                default => null,
            };
        @endphp

        @if ($trendSeries)
            <x-ui.card :title="match ($tab) { 'flights' => 'Flight revenue', 'tours' => 'Tour revenue', default => 'Travel sales' }" :description="'Bookings that count as sales, by booking date · '.$rangeLabel">
                <x-chart.columns :points="$report->trend()" :series="$trendSeries" money :label="'Revenue over '.$rangeLabel" empty="No sales in this period." />
            </x-ui.card>
        @endif

        @if ($tab === 'flights')
            <div class="grid items-start gap-4 xl:grid-cols-2">
                @include('livewire.travel.partials.report-table', ['title' => 'Top routes', 'rows' => $report->routes(), 'chart' => ['series' => ['Bookings' => ['name' => 'Bookings', 'slot' => 1]]]])
                @include('livewire.travel.partials.report-table', ['title' => 'Airlines', 'rows' => $report->airlines(), 'chart' => ['series' => ['Revenue (KES)' => ['name' => 'Revenue', 'slot' => 1]], 'money' => true]])
            </div>
            @include('livewire.travel.partials.report-table', ['title' => 'Refunds by status', 'description' => 'Statuses as Tourlast Flights Super Admin reports them', 'rows' => $report->flightRefunds(), 'chart' => ['series' => ['Amount (KES)' => ['name' => 'Amount', 'slot' => 1]], 'money' => true]])
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
                    <p class="text-[13px] text-ink-muted">No review decisions in this period.</p>
                @endif
            </x-ui.card>
        @elseif ($tab === 'salespeople')
            @include('livewire.travel.partials.report-table', ['title' => 'Travel salespeople', 'description' => 'Targets show when the period is one whole month', 'rows' => $report->salespeople(), 'chart' => ['series' => ['Flight revenue (KES)' => ['name' => 'Flights', 'slot' => 1], 'Tour revenue (KES)' => ['name' => 'Tours & experiences', 'slot' => 2]], 'money' => true]])
        @endif
    </div>
</div>
