{{-- Shared flight booking table cells, rendered in the order given by $cells. --}}
@foreach ($cells as $cell)
    @switch($cell)
        @case('ref')
            <td>
                <a href="{{ route('travel.flights.show', $booking) }}" wire:navigate class="grid leading-tight">
                    <span class="font-semibold text-ink hover:text-brand-text">{{ $booking->booking_reference ?? $booking->external_id }}</span>
                    <span class="font-mono text-[12px] text-ink-subtle">{{ $booking->pnr ? 'PNR '.$booking->pnr : $booking->external_id }}</span>
                </a>
            </td>
            @break
        @case('passenger')
            <td>
                <div class="grid leading-tight">
                    <span class="text-ink">{{ $booking->passengers->first()?->name ?? $booking->customer_name ?? '—' }}</span>
                    @if ($booking->passenger_count > 1)
                        <span class="text-[13px] text-ink-subtle">+{{ $booking->passenger_count - 1 }} more</span>
                    @endif
                </div>
            </td>
            @break
        @case('ticket')
            @php($tickets = $booking->passengers->pluck('ticket_number')->filter()->values())
            <td>
                <div class="grid leading-tight">
                    <span class="font-mono text-[12.5px] text-ink">{{ $tickets->first() ?? 'Not issued' }}</span>
                    @if ($tickets->count() > 1)
                        <span class="text-[13px] text-ink-subtle" title="{{ $tickets->implode(', ') }}">+{{ $tickets->count() - 1 }} more</span>
                    @endif
                </div>
            </td>
            @break
        @case('route')
            <td class="font-medium whitespace-nowrap text-ink">{{ $booking->route() }}@if ($booking->trip_type === 'return')<span class="text-xs font-normal text-ink-subtle"> · return</span>@endif</td>
            @break
        @case('airline')
            <td class="text-ink-muted">{{ $booking->airline_name ?? $booking->airline_code ?? '—' }}</td>
            @break
        @case('status')
            <td><x-ui.pill :tone="\App\Models\FlightBooking::statusTone($booking->booking_status)">{{ \App\Models\FlightBooking::statusLabel($booking->booking_status) }}</x-ui.pill></td>
            @break
        @case('salesperson')
            <td class="text-ink-muted">{{ $booking->salesperson?->name ?? 'Not attributed' }}</td>
            @break
        @case('actions')
            <td class="text-right whitespace-nowrap">
                <x-ui.button size="sm" variant="ghost" :href="route('travel.flights.show', $booking)" wire:navigate>View</x-ui.button>
                <x-ui.button size="sm" variant="ghost" :href="$booking->adminUrl()" target="_blank" rel="noopener">Open in Flights Admin</x-ui.button>
            </td>
            @break
    @endswitch
@endforeach
