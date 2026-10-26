@php
    $money = fn ($value, string $currency) => $currency.' '.number_format((float) $value);
    $kes = fn ($value) => $money($value, 'KES');
    $usd = fn ($value) => $money($value, 'USD');
    $link = fn (string $route, array $parameters = []) => \Illuminate\Support\Facades\Route::has($route) ? route($route, $parameters) : null;

    // What needs a person today, biggest worries first. Hidden when the count is zero.
    $attention = array_values(array_filter([
        ['count' => $tours['pending_approval'], 'one' => 'package waiting for approval', 'many' => 'packages waiting for approval', 'route' => 'travel.approvals.index', 'icon' => 'check-circle'],
        ['count' => $tours['pending_cancellations'], 'one' => 'cancellation to decide', 'many' => 'cancellations to decide', 'route' => 'travel.cancellations.index', 'icon' => 'alert'],
        ['count' => $tours['pending_refunds'], 'one' => 'tour refund to pay', 'many' => 'tour refunds to pay', 'route' => 'travel.cancellations.index', 'icon' => 'alert'],
        ['count' => $tours['pending_bookings'], 'one' => 'booking waiting for payment', 'many' => 'bookings waiting for payment', 'route' => 'travel.bookings.index', 'icon' => 'clock'],
        ['count' => $flights['refunds_pending'], 'one' => 'flight refund pending', 'many' => 'flight refunds pending', 'route' => 'travel.flights.index', 'icon' => 'plane'],
        ['count' => $expiringContracts->count(), 'one' => 'contract ending soon', 'many' => 'contracts ending soon', 'route' => 'travel.contracts.index', 'icon' => 'calendar'],
        ['count' => $lowAvailability->count(), 'one' => 'departure nearly full', 'many' => 'departures nearly full', 'route' => 'travel.departures.index', 'icon' => 'users'],
    ], fn (array $item): bool => $item['count'] > 0));
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

    <div wire:loading.delay.class="opacity-60" wire:target="scope" class="grid gap-5 transition-opacity">
        {{-- Needs you now --}}
        <section aria-labelledby="needs-you" class="grid gap-2">
            <h2 id="needs-you" class="text-[13px] font-semibold text-ink">Needs you now</h2>
            @if ($attention === [])
                <p class="flex items-center gap-2 rounded-xl border border-line bg-surface px-4 py-3 text-[13px] text-ink-muted shadow-card">
                    <x-ui.icon name="check-circle" class="size-4 text-success" /> Nothing is waiting. Approvals, payments, refunds and contracts are all up to date.
                </p>
            @else
                <ul class="flex flex-wrap gap-2">
                    @foreach ($attention as $item)
                        @php($url = $link($item['route']))
                        <li>
                            <{{ $url ? 'a' : 'span' }} @if ($url) href="{{ $url }}" wire:navigate @endif class="inline-flex min-h-10 items-center gap-2 rounded-full border border-warning/40 bg-warning-soft/50 px-3.5 py-1.5 text-[13px] text-ink {{ $url ? 'hover:bg-warning-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand' : '' }}">
                                <x-ui.icon :name="$item['icon']" class="size-4 text-warning" />
                                <span><strong class="tabular font-bold">{{ number_format($item['count']) }}</strong> {{ $item['count'] === 1 ? $item['one'] : $item['many'] }}</span>
                            </{{ $url ? 'a' : 'span' }}>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- This month at a glance: flights are in USD, tours in KES, so they are never added together. --}}
        <section aria-labelledby="month-glance" class="grid gap-2">
            <h2 id="month-glance" class="text-[13px] font-semibold text-ink">{{ $team ? 'This month, whole team' : 'This month, your sales' }}</h2>
            <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-3 xl:grid-cols-6">
                <x-ui.kpi size="lg" label="Flight bookings" :value="number_format($flights['month'])" :href="$link('travel.flights.index')" />
                <x-ui.kpi size="lg" label="Flight revenue (USD)" :value="$usd($flights['revenue'])" :href="$link('travel.flights.index')" />
                <x-ui.kpi size="lg" label="Tour bookings" :value="number_format($tours['bookings'])" :href="$link('travel.bookings.index')" />
                <x-ui.kpi size="lg" label="Tour revenue (KES)" :value="$kes($tours['revenue'])" :href="$link('travel.bookings.index')" />
                <x-ui.kpi size="lg" label="Tour slots sold" :value="number_format($tours['slots_sold'])" :href="$link('travel.departures.index')" />
                <x-ui.kpi size="lg" label="Tours leaving in 30 days" :value="number_format($tours['upcoming_tours'])" :href="$link('travel.departures.index')" />
            </dl>

            <details class="group rounded-xl border border-line bg-surface shadow-card">
                <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 px-4 py-2 text-[13px] font-medium text-ink select-none">
                    More numbers
                    <svg class="size-4 text-ink-subtle transition-transform group-open:rotate-180" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                </summary>
                <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-b-xl border-t border-line bg-line sm:grid-cols-3 lg:grid-cols-5">
                    <x-ui.kpi label="Flights booked today" :value="number_format($flights['today'])" :href="$link('travel.flights.index')" />
                    <x-ui.kpi label="Upcoming flights" :value="number_format($flights['upcoming'])" :href="$link('travel.flights.index')" />
                    <x-ui.kpi label="Flights cancelled this month" :value="number_format($flights['cancelled'])" :href="$link('travel.flights.index')" />
                    <x-ui.kpi label="Flight refunds pending" :value="number_format($flights['refunds_pending'])" :tone="$flights['refunds_pending'] ? 'text-warning' : 'text-ink'" :href="$link('travel.flights.index')" />
                    <x-ui.kpi label="Flight refunds completed" :value="number_format($flights['refunds_completed'])" :href="$link('travel.flights.index')" />
                    @if ($seesMoney)
                        <x-ui.kpi label="Markup on flights (USD)" :value="$usd($flights['markup'])" :href="$link('travel.flights.index')" />
                    @endif
                    <x-ui.kpi label="Active providers" :value="number_format($tours['providers'])" :href="$link('travel.providers.index')" />
                    <x-ui.kpi label="Active packages" :value="number_format($tours['active'])" :href="$link('travel.packages.index')" />
                    <x-ui.kpi label="Published packages" :value="number_format($tours['published'])" tone="text-success" :href="$link('travel.packages.index')" />
                    <x-ui.kpi label="Packages pending approval" :value="number_format($tours['pending_approval'])" :tone="$tours['pending_approval'] ? 'text-warning' : 'text-ink'" :href="$link('travel.approvals.index')" />
                    <x-ui.kpi label="Pending cancellations" :value="number_format($tours['pending_cancellations'])" :tone="$tours['pending_cancellations'] ? 'text-warning' : 'text-ink'" :href="$link('travel.cancellations.index')" />
                    <x-ui.kpi label="Pending tour refunds" :value="number_format($tours['pending_refunds'])" :tone="$tours['pending_refunds'] ? 'text-warning' : 'text-ink'" :href="$link('travel.cancellations.index')" />
                </dl>
            </details>
        </section>

        {{-- Two charts, one currency each: USD and KES share no axis. --}}
        <div class="grid gap-4 lg:grid-cols-2">
            <x-ui.card :title="$team ? 'Flight revenue, last 6 months' : 'My flight revenue, last 6 months'" description="USD, by booking month">
                <x-chart.columns :points="$trend" money label="Flight revenue per month in USD" empty="No flight sales in the last 6 months."
                    :series="[['key' => 'flight_revenue', 'name' => 'Flights', 'slot' => 1, 'currency' => 'USD']]" />
            </x-ui.card>
            <x-ui.card :title="$team ? 'Tour revenue, last 6 months' : 'My tour revenue, last 6 months'" description="KES, by booking month">
                <x-chart.columns :points="$trend" money label="Tour revenue per month in KES" empty="No tour sales in the last 6 months."
                    :series="[['key' => 'tour_revenue', 'name' => 'Tours & experiences', 'slot' => 2, 'currency' => 'KES']]" />
            </x-ui.card>
        </div>

        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="grid gap-4">
                {{-- My sales actions --}}
                <x-ui.card :title="$team ? 'Sales actions' : 'My sales actions'" description="Follow-ups, packages to submit, bookings to confirm, renewals and pre-trip checks, most urgent first" :padding="false">
                    @forelse ($actions as $action)
                        @php($tag = $action['url'] ? 'a' : 'div')
                        <{{ $tag }} @if ($action['url']) href="{{ $action['url'] }}" wire:navigate @endif class="flex min-h-14 items-center gap-3 border-b border-line px-4 py-2.5 last:border-b-0 hover:bg-surface-muted/50">
                            <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-soft text-brand-text"><x-ui.icon :name="$action['icon']" class="size-4" /></span>
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
                    <x-ui.card title="Travel salespeople this month" description="Ranked by bookings. Flights are in USD and tours in KES, so the two are shown side by side." :padding="false">
                        <x-chart.bars class="border-b border-line px-4 py-3" money label="Travel sales per salesperson this month"
                            :series="[['key' => 'flight_revenue', 'name' => 'Flights', 'slot' => 1, 'currency' => 'USD'], ['key' => 'tour_revenue', 'name' => 'Tours & experiences', 'slot' => 2, 'currency' => 'KES']]"
                            :rows="$salespeople->map(fn ($row) => ['label' => $row['user']->name, 'values' => ['flight_revenue' => $row['flight_revenue'], 'tour_revenue' => $row['tour_revenue']]])->all()" />

                        {{-- Phones: one card per person --}}
                        <ul class="divide-y divide-line sm:hidden">
                            @forelse ($salespeople as $row)
                                <li wire:key="tsm-{{ $row['user']->id }}" class="grid gap-2 px-4 py-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="flex min-w-0 items-center gap-2.5"><x-ui.avatar :user="$row['user']" size="sm" /><span class="truncate font-semibold text-ink">{{ $row['user']->name }}</span></span>
                                        <span class="tabular shrink-0 text-xs text-ink-muted"><strong class="text-sm text-ink">{{ number_format($row['bookings']) }}</strong> bookings</span>
                                    </div>
                                    <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                                        <div><dt class="text-ink-subtle">Flights</dt><dd class="tabular text-ink">{{ number_format($row['flight_bookings']) }} · {{ $usd($row['flight_revenue']) }}</dd></div>
                                        <div><dt class="text-ink-subtle">Tours</dt><dd class="tabular text-ink">{{ number_format($row['tour_bookings']) }} · {{ $kes($row['tour_revenue']) }}</dd></div>
                                        <div><dt class="text-ink-subtle">Packages created</dt><dd class="tabular text-ink">{{ $row['packages_created'] }}</dd></div>
                                    </dl>
                                </li>
                            @empty
                                <li><x-ui.empty-state title="No travel salespeople yet" /></li>
                            @endforelse
                        </ul>

                        {{-- Tablets and up: the table --}}
                        <x-ui.table-card :sticky="false" class="hidden rounded-none border-0 shadow-none sm:block">
                            <table class="w-full min-w-[720px] text-sm">
                                <thead class="text-left uppercase">
                                    <tr>
                                        <th class="text-left">Salesperson</th>
                                        <th class="text-right">Flight bookings</th>
                                        <th class="text-right">Flight revenue (USD)</th>
                                        <th class="text-right">Tour bookings</th>
                                        <th class="text-right">Tour revenue (KES)</th>
                                        <th class="text-right">Total bookings</th>
                                        <th class="text-right">Packages created</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    @forelse ($salespeople as $row)
                                        <tr wire:key="ts-{{ $row['user']->id }}">
                                            <td><div class="flex items-center gap-2.5"><x-ui.avatar :user="$row['user']" size="sm" /><span class="font-semibold text-ink">{{ $row['user']->name }}</span></div></td>
                                            <td class="tabular text-right">{{ number_format($row['flight_bookings']) }}</td>
                                            <td class="tabular text-right">{{ $usd($row['flight_revenue']) }}</td>
                                            <td class="tabular text-right">{{ number_format($row['tour_bookings']) }}</td>
                                            <td class="tabular text-right">{{ $kes($row['tour_revenue']) }}</td>
                                            <td class="tabular text-right font-bold text-ink">{{ number_format($row['bookings']) }}</td>
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
                        <div class="grid gap-3.5">
                            @foreach ($targets as $target)
                                @php($pct = $target['target'] ? min(100, (int) round($target['actual'] / max(1, $target['target']) * 100)) : null)
                                <div class="grid gap-1">
                                    <div class="flex items-baseline justify-between gap-2 text-[13px]">
                                        <span class="font-medium text-ink">{{ $target['label'] }}</span>
                                        <span class="tabular text-right text-ink-muted">
                                            {{ $target['money'] ? $money($target['actual'], $target['currency']) : number_format($target['actual']) }}
                                            @if ($target['target']) / {{ $target['money'] ? $money($target['target'], $target['currency']) : number_format($target['target']) }} @endif
                                        </span>
                                    </div>
                                    @if ($pct !== null)
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-surface-muted" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $target['label'] }}">
                                                <div class="h-full rounded-full {{ $pct >= 100 ? 'bg-success' : 'bg-brand' }}" style="width: {{ $pct }}%"></div>
                                            </div>
                                            <span class="tabular w-9 text-right text-xs font-medium text-ink-muted">{{ $pct }}%</span>
                                        </div>
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
</div>
