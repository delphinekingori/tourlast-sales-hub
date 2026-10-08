@php
    $kes = fn ($value) => 'KES '.number_format((float) $value);
    $link = fn (string $route, array $parameters = []) => \Illuminate\Support\Facades\Route::has($route) ? route($route, $parameters) : null;
@endphp

<div class="grid gap-5">
    <x-ui.page-header :eyebrow="$team ? 'Whole travel team · '.now()->format('F Y') : now()->format('l, j F')" title="Travel dashboard" :description="$team ? 'Flights, tours and experiences across every travel salesperson this month.' : 'Your flights, tours and experiences this month, and what needs doing next.'">
        <x-slot:actions>
            @if ($url = $link('travel.search'))
                <form method="GET" action="{{ $url }}" role="search" class="w-full sm:w-64">
                    <x-ui.search name="q" placeholder="Search travel…" :wide="true" />
                </form>
            @endif
            @if ($managesAll)
                <x-ui.segmented wire:model.live="scope" :options="['mine' => 'My figures', 'team' => 'Whole team']" />
            @endif
            <x-ui.button variant="secondary" icon="calendar" x-on:click="$dispatch('open-schedule', {})">Schedule follow-up</x-ui.button>
            @if ($url = $link('travel.packages.create'))
                <x-ui.button icon="plus" :href="$url" wire:navigate>New package</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Flights --}}
    <section class="grid gap-2" aria-labelledby="kpi-flights">
        <div class="flex items-center gap-2">
            <x-ui.icon name="plane" class="size-4 text-brand-text" />
            <h2 id="kpi-flights" class="text-[13px] font-semibold text-ink">Flights</h2>
            @if ($url = $link('travel.flights.index'))
                <a href="{{ $url }}" wire:navigate class="ml-auto text-xs font-medium text-brand-text hover:underline">All flight bookings</a>
            @endif
        </div>
        <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-4">
            @foreach (array_filter([
                ['Bookings today', number_format($flights['today'])],
                ['Bookings this month', number_format($flights['month'])],
                ['Upcoming flights', number_format($flights['upcoming'])],
                ['Cancelled this month', number_format($flights['cancelled'])],
                ['Refunds pending', number_format($flights['refunds_pending'])],
                ['Refunds completed', number_format($flights['refunds_completed'])],
                ['Booking revenue', $kes($flights['revenue'])],
                $seesMoney ? ['Markup', $kes($flights['markup'])] : null,
            ]) as [$label, $value])
                <div @class(['grid gap-0.5 bg-surface px-4 py-3', 'col-span-2' => ! $seesMoney && $loop->last])>
                    <dt class="truncate text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                    <dd class="tabular truncate text-xl leading-tight font-bold text-ink">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    {{-- Tours & experiences --}}
    <section class="grid gap-2" aria-labelledby="kpi-tours">
        <div class="flex items-center gap-2">
            <x-ui.icon name="map" class="size-4 text-brand-text" />
            <h2 id="kpi-tours" class="text-[13px] font-semibold text-ink">Tours &amp; experiences</h2>
            @if ($url = $link('travel.packages.index'))
                <a href="{{ $url }}" wire:navigate class="ml-auto text-xs font-medium text-brand-text hover:underline">All packages</a>
            @endif
        </div>
        <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-5">
            @foreach ([
                ['Active providers', number_format($tours['providers']), 'text-ink'],
                ['Active packages', number_format($tours['active']), 'text-ink'],
                ['Pending approval', number_format($tours['pending_approval']), $tours['pending_approval'] ? 'text-warning' : 'text-ink'],
                ['Published', number_format($tours['published']), 'text-success'],
                ['Bookings this month', number_format($tours['bookings']), 'text-ink'],
                ['Tour revenue', $kes($tours['revenue']), 'text-ink'],
                ['Slots sold', number_format($tours['slots_sold']), 'text-ink'],
                ['Tours in 30 days', number_format($tours['upcoming_tours']), 'text-ink'],
                ['Pending cancellations', number_format($tours['pending_cancellations']), $tours['pending_cancellations'] ? 'text-warning' : 'text-ink'],
                ['Pending refunds', number_format($tours['pending_refunds']), $tours['pending_refunds'] ? 'text-warning' : 'text-ink'],
            ] as [$label, $value, $tone])
                <div class="grid gap-0.5 bg-surface px-4 py-3">
                    <dt class="truncate text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                    <dd class="tabular truncate text-xl leading-tight font-bold {{ $tone }}">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <x-ui.card :title="$team ? 'Travel sales, last 6 months' : 'My travel sales, last 6 months'" description="Revenue from flight and tour bookings that count as sales, by booking month">
        <x-chart.columns :points="$trend" money label="Travel sales revenue per month, flights and tours" empty="No travel sales in the last 6 months."
            :series="[['key' => 'flight_revenue', 'name' => 'Flights', 'slot' => 1], ['key' => 'tour_revenue', 'name' => 'Tours & experiences', 'slot' => 2]]" />
    </x-ui.card>

    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="grid gap-4">
            {{-- My sales actions --}}
            <x-ui.card :title="$team ? 'Sales actions' : 'My sales actions'" description="Follow-ups, packages to submit, bookings to confirm, renewals and pre-trip checks, most urgent first" :padding="false">
                @forelse ($actions as $action)
                    @php($tag = $action['url'] ? 'a' : 'div')
                    <{{ $tag }} @if ($action['url']) href="{{ $action['url'] }}" wire:navigate @endif class="flex items-center gap-3 border-b border-line px-4 py-2.5 last:border-b-0 hover:bg-surface-muted/50">
                        <span class="grid size-7 shrink-0 place-items-center rounded-full bg-brand-soft text-brand-text"><x-ui.icon :name="$action['icon']" class="size-3.5" /></span>
                        <span class="grid min-w-0 flex-1 leading-tight">
                            <span class="truncate text-[13px] font-semibold text-ink">{{ $action['label'] }}</span>
                            <span class="truncate text-xs text-ink-muted">{{ $action['detail'] }}</span>
                        </span>
                        <x-ui.pill :tone="$action['tone']">{{ $action['when'] }}</x-ui.pill>
                    </{{ $tag }}>
                @empty
                    <x-ui.empty-state icon="check-circle" title="Nothing waiting" description="No follow-ups, approvals, renewals or bookings need you right now." />
                @endforelse
            </x-ui.card>

            @if ($team)
                {{-- Team performance --}}
                <x-ui.card title="Travel salespeople this month" :padding="false">
                    <x-chart.bars class="border-b border-line px-4 py-3" money label="Travel sales per salesperson this month"
                        :series="[['key' => 'flight_revenue', 'name' => 'Flights', 'slot' => 1], ['key' => 'tour_revenue', 'name' => 'Tours & experiences', 'slot' => 2]]"
                        :rows="$salespeople->map(fn ($row) => ['label' => $row['user']->name, 'values' => ['flight_revenue' => $row['flight_revenue'], 'tour_revenue' => $row['tour_revenue']]])->all()" />
                    <x-ui.table-card :sticky="false" class="rounded-none border-0 shadow-none">
                        <table class="w-full min-w-[720px] text-sm">
                            <thead class="text-left uppercase">
                                <tr>
                                    <th class="text-left">Salesperson</th>
                                    <th class="text-right">Flight bookings</th>
                                    <th class="text-right">Flight revenue</th>
                                    <th class="text-right">Tour bookings</th>
                                    <th class="text-right">Tour revenue</th>
                                    <th class="text-right">Total travel sales</th>
                                    <th class="text-right">Packages created</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @forelse ($salespeople as $row)
                                    <tr wire:key="ts-{{ $row['user']->id }}">
                                        <td><div class="flex items-center gap-2.5"><x-ui.avatar :user="$row['user']" size="sm" /><span class="font-semibold text-ink">{{ $row['user']->name }}</span></div></td>
                                        <td class="tabular text-right">{{ number_format($row['flight_bookings']) }}</td>
                                        <td class="tabular text-right">{{ $kes($row['flight_revenue']) }}</td>
                                        <td class="tabular text-right">{{ number_format($row['tour_bookings']) }}</td>
                                        <td class="tabular text-right">{{ $kes($row['tour_revenue']) }}</td>
                                        <td class="tabular text-right font-bold text-ink">{{ $kes($row['total']) }}</td>
                                        <td class="tabular text-right text-ink-muted">{{ $row['packages_created'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7"><x-ui.empty-state title="No travel salespeople yet" /></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </x-ui.table-card>
                </x-ui.card>
            @endif
        </div>

        <div class="grid gap-4">
            @if ($targets !== [])
                <x-ui.card title="My targets" :description="now()->format('F Y').' · set by Sales Admin'">
                    <div class="grid gap-3">
                        @foreach ($targets as $target)
                            @php($pct = $target['target'] ? min(100, (int) round($target['actual'] / max(1, $target['target']) * 100)) : null)
                            <div class="grid gap-1">
                                <div class="flex items-baseline justify-between gap-2 text-[13px]">
                                    <span class="font-medium text-ink">{{ $target['label'] }}</span>
                                    <span class="tabular text-ink-muted">
                                        {{ $target['money'] ? $kes($target['actual']) : number_format($target['actual']) }}
                                        @if ($target['target']) / {{ $target['money'] ? $kes($target['target']) : number_format($target['target']) }} @endif
                                    </span>
                                </div>
                                @if ($pct !== null)
                                    <div class="h-1.5 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-brand" style="width: {{ $pct }}%"></div></div>
                                @else
                                    <span class="text-xs text-ink-subtle">No target set</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            @if ($approvals)
                <x-ui.card title="Package approvals" description="This month">
                    <dl class="grid grid-cols-3 gap-3 text-center">
                        @foreach ([['Pending', $approvals['pending'], 'text-warning'], ['Approved', $approvals['approved'], 'text-success'], ['Rejected / changes', $approvals['rejected'], 'text-danger']] as [$label, $value, $tone])
                            <div class="grid gap-0.5"><dt class="text-xs text-ink-subtle">{{ $label }}</dt><dd class="tabular text-xl font-bold {{ $tone }}">{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                </x-ui.card>
            @endif

            <x-ui.card title="Low availability" description="Upcoming departures that are nearly full or full" :padding="false">
                @forelse ($lowAvailability as $departure)
                    @php($sold = $departure->soldSlots() + $departure->reservedSlots())
                    <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-2.5 last:border-b-0">
                        <span class="grid min-w-0 leading-tight">
                            <span class="truncate text-[13px] font-semibold text-ink">{{ $departure->package?->name }}</span>
                            <span class="text-xs text-ink-muted">{{ $departure->starts_on->format('D j M') }} · {{ $sold }} / {{ $departure->capacity }} taken · {{ max(0, $departure->capacity - $sold) }} {{ \Illuminate\Support\Str::plural('slot', max(0, $departure->capacity - $sold)) }} left</span>
                        </span>
                        <x-ui.pill :tone="$departure->availabilityStatus()->tone()">{{ $departure->availabilityStatus()->label() }}</x-ui.pill>
                    </div>
                @empty
                    <p class="px-4 py-3 text-[13px] text-ink-muted">Every upcoming departure has room.</p>
                @endforelse
            </x-ui.card>

            <x-ui.card title="Contracts expiring" description="Within the next 30 days" :padding="false">
                @forelse ($expiringContracts as $contract)
                    @php($days = $contract->daysUntilExpiry())
                    <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-2.5 last:border-b-0">
                        <span class="grid min-w-0 leading-tight">
                            <span class="truncate text-[13px] font-semibold text-ink">{{ $contract->provider?->name }}</span>
                            <span class="text-xs text-ink-muted">{{ $contract->contract_number }} · ends {{ $contract->ends_on->format('j M Y') }}</span>
                        </span>
                        <x-ui.pill :tone="$days < 0 ? 'danger' : 'warning'">{{ $days < 0 ? 'Expired' : ($days === 0 ? 'Ends today' : $days.' '.\Illuminate\Support\Str::plural('day', $days)) }}</x-ui.pill>
                    </div>
                @empty
                    <p class="px-4 py-3 text-[13px] text-ink-muted">No contracts expire in the next 30 days.</p>
                @endforelse
            </x-ui.card>
        </div>
    </div>
</div>
