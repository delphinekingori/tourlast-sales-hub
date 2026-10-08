@php
    $money = fn ($amount, $currency = 'KES') => $amount === null ? '—' : $currency.' '.number_format((float) $amount);
    $sortIcon = fn (string $column) => $sort === $column ? ($dir === 'asc' ? '↑' : '↓') : '';
    $filtersActive = $search !== '' || $status !== '' || $airline !== '' || $origin !== '' || $destination !== '' || $bookedFrom !== '' || $bookedTo !== '' || $departFrom !== '' || $departTo !== '' || $salesperson !== '' || $mine;
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="Flights" description="Bookings from Tourlast Flights Super Admin. This is a read-only copy: bookings, payments, cancellations and refunds are managed in Flights Super Admin." />

    @include('livewire.travel.flights.partials.sync-status')

    {{-- Summary --}}
    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-4 {{ $seesMoney ? 'xl:grid-cols-8' : 'xl:grid-cols-7' }}" wire:loading.delay.class="opacity-60">
        @foreach (array_filter([
            ['Bookings today', number_format($summary['today']), 'text-ink'],
            ['Bookings this month', number_format($summary['month']), 'text-ink'],
            ['Upcoming flights', number_format($summary['upcoming']), 'text-brand-text'],
            ['Cancelled this month', number_format($summary['cancelled']), $summary['cancelled'] ? 'text-danger' : 'text-ink'],
            ['Refunds pending', number_format($summary['refundsPending']), $summary['refundsPending'] ? 'text-warning' : 'text-ink'],
            ['Refunds completed', number_format($summary['refundsCompleted']), 'text-ink'],
            ['Booking revenue (month)', $money($summary['revenue']), 'text-ink'],
            $seesMoney ? ['Markup (month)', $money($summary['markup']), 'text-success'] : null,
        ]) as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="truncate text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular truncate text-xl leading-tight font-bold {{ $tone }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    {{-- Views --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-ui.segmented wire:model.live="view" :options="\App\Livewire\Travel\Flights\Index::Views" />
        <label class="inline-flex items-center gap-2 text-[13px] text-ink-muted">
            <input type="checkbox" wire:model.live="mine" class="size-4 rounded border-line-strong text-brand focus:ring-brand">
            My bookings only
        </label>
    </div>

    {{-- Filters --}}
    <div class="grid gap-3 rounded-xl border border-line bg-surface p-3 shadow-card">
        <div class="flex flex-wrap items-end gap-2">
            <div class="min-w-64 flex-1">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Booking ref, PNR, ticket no., customer or passenger" wide />
            </div>
            @if ($view !== 'customers')
                <div class="w-44">
                    <x-ui.select :label="$view === 'refunds' ? 'Refund status' : 'Status'" wire:model.live="status" id="fl-status">
                        <option value="">All</option>
                        @foreach ($view === 'refunds' ? $refundStatuses : $statuses as $value)
                            <option value="{{ $value }}">{{ \App\Models\FlightBooking::statusLabel($value) }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            @endif
            <div class="w-44">
                <x-ui.select label="Airline" wire:model.live="airline" id="fl-airline">
                    <option value="">All airlines</option>
                    @foreach ($airlines as $code => $name)
                        <option value="{{ $code }}">{{ $name ?: $code }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="w-28">
                <x-ui.select label="From" wire:model.live="origin" id="fl-origin">
                    <option value="">Any</option>
                    @foreach ($airports as $code)
                        <option value="{{ $code }}">{{ $code }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="w-28">
                <x-ui.select label="To" wire:model.live="destination" id="fl-destination">
                    <option value="">Any</option>
                    @foreach ($airports as $code)
                        <option value="{{ $code }}">{{ $code }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            @if ($managesAll)
                <div class="w-48">
                    <x-ui.select label="Salesperson" wire:model.live="salesperson" id="fl-salesperson">
                        <option value="">Everyone</option>
                        @foreach ($salespeople as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            @endif
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <div class="w-36"><x-ui.input label="Booked from" type="date" wire:model.live="bookedFrom" id="fl-booked-from" /></div>
            <div class="w-36"><x-ui.input label="Booked to" type="date" wire:model.live="bookedTo" id="fl-booked-to" /></div>
            <div class="w-36"><x-ui.input label="Departing from" type="date" wire:model.live="departFrom" id="fl-depart-from" /></div>
            <div class="w-36"><x-ui.input label="Departing to" type="date" wire:model.live="departTo" id="fl-depart-to" /></div>
            @if ($filtersActive)
                <x-ui.button size="sm" variant="ghost" icon="x" wire:click="clearFilters">Clear filters</x-ui.button>
            @endif
            <span class="ml-auto pb-1 text-[13px] font-semibold text-ink">{{ number_format($rows->total()) }} {{ $view === 'customers' ? \Illuminate\Support\Str::plural('customer', $rows->total()) : \Illuminate\Support\Str::plural('booking', $rows->total()) }}</span>
        </div>
    </div>

    @if ($view === 'refunds' && $refundSummary->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2 text-[13px]">
            @foreach ($refundSummary as $line)
                <x-ui.pill :tone="\App\Models\FlightBooking::statusTone($line->refund_status)">{{ \App\Models\FlightBooking::statusLabel($line->refund_status) }} · {{ $line->total }} · {{ $money($line->amount) }}</x-ui.pill>
            @endforeach
        </div>
    @endif

    <x-ui.table-card :paginator="$rows">
        @switch($view)
            @case('customers')
                <table class="w-full min-w-[900px] text-sm">
                    <thead class="text-left uppercase">
                        <tr><th>Customer</th><th>Contact</th><th class="text-right">Bookings</th><th class="text-right">Total spent</th><th>Last booking</th><th>Next flight</th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($rows as $customer)
                            @php $seesContact = $managesAll || $customer->is_mine; @endphp
                            <tr wire:key="cust-{{ md5($customer->customer_key) }}">
                                <td class="font-semibold text-ink">{{ $customer->customer_name ?? '—' }}</td>
                                <td class="text-ink-muted">
                                    <div class="grid leading-tight">
                                        @if ($seesContact)
                                            <span>{{ $customer->customer_email ?? '—' }}</span><span class="text-[13px] text-ink-subtle">{{ $customer->customer_phone }}</span>
                                        @else
                                            <span>{{ $customer->customer_email ? \Illuminate\Support\Str::substr($customer->customer_email, 0, 1).'***@'.\Illuminate\Support\Str::after($customer->customer_email, '@') : '—' }}</span>
                                            <span class="text-[13px] text-ink-subtle">{{ \App\Models\TravelClient::mask($customer->customer_phone) }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="tabular text-right text-ink">{{ $customer->bookings_count }}</td>
                                <td class="tabular text-right text-ink">{{ $money($customer->total_spent) }}</td>
                                <td class="text-ink-muted">{{ $customer->last_booked_at ? \Illuminate\Support\Carbon::parse($customer->last_booked_at)->format('j M Y') : '—' }}</td>
                                <td class="text-ink-muted">{{ $customer->next_flight_at ? \Illuminate\Support\Carbon::parse($customer->next_flight_at)->format('j M Y, H:i') : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-ui.empty-state icon="users" title="No flight customers match these filters" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                @break

            @case('upcoming')
                <table class="w-full min-w-[900px] text-sm">
                    <thead class="text-left uppercase">
                        <tr><th>Departure</th><th>Booking</th><th>Passenger</th><th>Ticket no.</th><th>Route</th><th>Airline</th><th>Status</th><th>Salesperson</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @php $day = null; @endphp
                        @forelse ($rows as $booking)
                            @if ($day !== $booking->departure_at->toDateString())
                                @php $day = $booking->departure_at->toDateString(); @endphp
                                <tr wire:key="day-{{ $day }}" class="bg-surface-muted/60"><td colspan="9" class="text-xs font-semibold text-ink-muted">{{ $booking->departure_at->isToday() ? 'Today' : ($booking->departure_at->isTomorrow() ? 'Tomorrow' : $booking->departure_at->format('l j F')) }}</td></tr>
                            @endif
                            <tr wire:key="up-{{ $booking->id }}">
                                <td class="tabular font-semibold text-ink">{{ $booking->departure_at->format('H:i') }}</td>
                                @include('livewire.travel.flights.partials.booking-cells', ['cells' => ['ref', 'passenger', 'ticket', 'route', 'airline', 'status', 'salesperson', 'actions']])
                            </tr>
                        @empty
                            <tr><td colspan="9"><x-ui.empty-state icon="plane" title="No upcoming flights" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                @break

            @case('cancellations')
                <table class="w-full min-w-[1000px] text-sm">
                    <thead class="text-left uppercase">
                        <tr><th>Booking</th><th>Passenger</th><th>Ticket no.</th><th>Route</th><th>Cancelled</th><th>Reason / status</th><th>Refund status</th><th class="text-right">Refund amount</th><th>Salesperson</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($rows as $booking)
                            <tr wire:key="can-{{ $booking->id }}">
                                @include('livewire.travel.flights.partials.booking-cells', ['cells' => ['ref', 'passenger', 'ticket', 'route']])
                                <td class="text-ink-muted">{{ $booking->cancelled_at?->format('j M Y') ?? '—' }}</td>
                                <td><div class="grid leading-tight"><span class="text-ink">{{ $booking->cancellation_reason ?? '—' }}</span><span class="text-[13px] text-ink-subtle">{{ \App\Models\FlightBooking::statusLabel($booking->cancellation_status) }}</span></div></td>
                                <td>@if ($booking->refund_status)<x-ui.pill :tone="\App\Models\FlightBooking::statusTone($booking->refund_status)">{{ \App\Models\FlightBooking::statusLabel($booking->refund_status) }}</x-ui.pill>@else<span class="text-ink-subtle">No refund</span>@endif</td>
                                <td class="tabular text-right text-ink">{{ $money($booking->refund_amount, $booking->currency) }}</td>
                                @include('livewire.travel.flights.partials.booking-cells', ['cells' => ['salesperson', 'actions']])
                            </tr>
                        @empty
                            <tr><td colspan="10"><x-ui.empty-state icon="plane" title="No cancellations match these filters" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                @break

            @case('refunds')
                <table class="w-full min-w-[1000px] text-sm">
                    <thead class="text-left uppercase">
                        <tr><th>Booking</th><th>Passenger</th><th>Ticket no.</th><th class="text-right">Refund amount</th><th>Refund status</th><th>Requested</th><th>Completed</th><th>Method</th><th>Salesperson</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($rows as $booking)
                            <tr wire:key="ref-{{ $booking->id }}">
                                @include('livewire.travel.flights.partials.booking-cells', ['cells' => ['ref', 'passenger', 'ticket']])
                                <td class="tabular text-right font-semibold text-ink">{{ $money($booking->refund_amount, $booking->currency) }}</td>
                                <td><x-ui.pill :tone="\App\Models\FlightBooking::statusTone($booking->refund_status)">{{ \App\Models\FlightBooking::statusLabel($booking->refund_status) }}</x-ui.pill></td>
                                <td class="text-ink-muted">{{ $booking->refund_requested_at?->format('j M Y') ?? '—' }}</td>
                                <td class="text-ink-muted">{{ $booking->refund_completed_at?->format('j M Y') ?? '—' }}</td>
                                <td class="text-ink-muted">{{ \App\Models\FlightBooking::statusLabel($booking->refund_method) }}</td>
                                @include('livewire.travel.flights.partials.booking-cells', ['cells' => ['salesperson', 'actions']])
                            </tr>
                        @empty
                            <tr><td colspan="10"><x-ui.empty-state icon="refresh" title="No refunds match these filters" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                @break

            @default
                <table class="w-full min-w-[1100px] text-sm [&_td]:whitespace-nowrap">
                    <thead class="text-left uppercase">
                        <tr>
                            <th>Booking ID</th><th>Passenger</th><th>Ticket no.</th><th>Route</th><th>Airline</th>
                            <th><button type="button" wire:click="sortBy('departure_at')" class="uppercase">Departure {{ $sortIcon('departure_at') }}</button></th>
                            <th><button type="button" wire:click="sortBy('booked_at')" class="uppercase">Booking date {{ $sortIcon('booked_at') }}</button></th>
                            <th class="text-right"><button type="button" wire:click="sortBy('total_amount')" class="uppercase">Amount {{ $sortIcon('total_amount') }}</button></th>
                            <th>Status</th><th>Salesperson</th><th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($rows as $booking)
                            <tr wire:key="bk-{{ $booking->id }}">
                                @include('livewire.travel.flights.partials.booking-cells', ['cells' => ['ref', 'passenger', 'ticket', 'route', 'airline']])
                                <td class="text-ink-muted">{{ $booking->departure_at?->format('j M Y, H:i') ?? '—' }}</td>
                                <td class="text-ink-muted">{{ $booking->booked_at?->format('j M Y') ?? '—' }}</td>
                                <td class="tabular text-right text-ink">{{ $money($booking->total_amount, $booking->currency) }}</td>
                                @include('livewire.travel.flights.partials.booking-cells', ['cells' => ['status', 'salesperson', 'actions']])
                            </tr>
                        @empty
                            <tr><td colspan="11"><x-ui.empty-state icon="plane" title="No flight bookings match these filters" description="{{ $sync->lastSyncedAt() ? 'Try a wider date range or clear the filters.' : 'Bookings appear here after the first sync from Flights Super Admin.' }}" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
        @endswitch
    </x-ui.table-card>
</div>
