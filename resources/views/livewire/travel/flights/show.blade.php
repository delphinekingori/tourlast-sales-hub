@php
    $money = fn ($amount) => $amount === null ? '—' : $booking->currency.' '.number_format((float) $amount, 2);
    $date = fn ($value) => $value?->format('j M Y, H:i') ?? '—';
@endphp

<div class="grid gap-5">
    <x-ui.page-header :title="($booking->booking_reference ?? $booking->external_id).' · '.$booking->route()" description="Bookings are managed in Tourlast Flights Super Admin. Changes made there appear here after the next sync.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow-right" :href="route('travel.flights.index')" wire:navigate>All flights</x-ui.button>
            <x-ui.button icon="link" :href="$booking->adminUrl()" target="_blank" rel="noopener">Open in Flights Admin</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.travel.flights.partials.sync-status')

    <div class="flex flex-wrap items-center gap-2">
        <x-ui.pill :tone="\App\Models\FlightBooking::statusTone($booking->booking_status)">Booking: {{ \App\Models\FlightBooking::statusLabel($booking->booking_status) }}</x-ui.pill>
        <x-ui.pill :tone="\App\Models\FlightBooking::statusTone($booking->payment_status)">Payment: {{ \App\Models\FlightBooking::statusLabel($booking->payment_status) }}</x-ui.pill>
        @if ($booking->cancellation_status)
            <x-ui.pill :tone="\App\Models\FlightBooking::statusTone($booking->cancellation_status)">Cancellation: {{ \App\Models\FlightBooking::statusLabel($booking->cancellation_status) }}</x-ui.pill>
        @endif
        @if ($booking->refund_status)
            <x-ui.pill :tone="\App\Models\FlightBooking::statusTone($booking->refund_status)">Refund: {{ \App\Models\FlightBooking::statusLabel($booking->refund_status) }}</x-ui.pill>
        @endif
    </div>

    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div class="grid gap-4">
            <x-ui.card title="Itinerary" :description="ucfirst(str_replace('_', ' ', (string) $booking->trip_type)).' · '.ucfirst((string) $booking->cabin)" :padding="false">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm [&_td]:px-4 [&_td]:py-2.5 [&_th]:px-4 [&_th]:py-2">
                        <thead class="bg-sidebar text-left text-[11px] font-medium tracking-[0.06em] text-sidebar-ink uppercase">
                            <tr><th>Flight</th><th>From</th><th>To</th><th>Departs</th><th>Arrives</th><th>Cabin</th></tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($booking->segments as $segment)
                                <tr wire:key="seg-{{ $segment->id }}">
                                    <td class="font-mono font-semibold text-ink">{{ $segment->flight_number ?? '—' }}</td>
                                    <td class="text-ink">{{ $segment->origin }}</td>
                                    <td class="text-ink">{{ $segment->destination }}</td>
                                    <td class="text-ink-muted">{{ $date($segment->departure_at) }}</td>
                                    <td class="text-ink-muted">{{ $date($segment->arrival_at) }}</td>
                                    <td class="text-ink-muted">{{ ucfirst((string) $segment->cabin) ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="text-ink">{{ $booking->airline_code }}</td>
                                    <td class="text-ink">{{ $booking->origin }}</td>
                                    <td class="text-ink">{{ $booking->destination }}</td>
                                    <td class="text-ink-muted">{{ $date($booking->departure_at) }}</td>
                                    <td class="text-ink-muted">{{ $date($booking->arrival_at) }}</td>
                                    <td class="text-ink-muted">{{ ucfirst((string) $booking->cabin) ?: '—' }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <x-ui.card :title="'Passengers ('.$booking->passengers->count().')'" :padding="false">
                <ul class="divide-y divide-line">
                    @forelse ($booking->passengers as $passenger)
                        <li wire:key="pax-{{ $passenger->id }}" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                            <span class="font-medium text-ink">{{ $passenger->name }} <span class="text-xs font-normal text-ink-subtle">· {{ ucfirst($passenger->passenger_type) }}</span></span>
                            <span class="font-mono text-[13px] text-ink-muted">{{ $passenger->ticket_number ? 'Ticket '.$passenger->ticket_number : 'No ticket yet' }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-3 text-sm text-ink-subtle">No passenger names were sent.</li>
                    @endforelse
                </ul>
            </x-ui.card>

            @if ($booking->isCancelled() || $booking->hasRefund())
                <x-ui.card title="Cancellation & refund" description="As recorded in Flights Super Admin">
                    <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        <div><dt class="text-xs text-ink-subtle">Cancellation status</dt><dd class="text-ink">{{ \App\Models\FlightBooking::statusLabel($booking->cancellation_status) }}</dd></div>
                        <div><dt class="text-xs text-ink-subtle">Cancelled</dt><dd class="text-ink">{{ $date($booking->cancelled_at) }}</dd></div>
                        <div><dt class="text-xs text-ink-subtle">Reason</dt><dd class="text-ink">{{ $booking->cancellation_reason ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-ink-subtle">Refund status</dt><dd class="text-ink">{{ \App\Models\FlightBooking::statusLabel($booking->refund_status) }}</dd></div>
                        <div><dt class="text-xs text-ink-subtle">Refund amount</dt><dd class="tabular text-ink">{{ $money($booking->refund_amount) }}</dd></div>
                        <div><dt class="text-xs text-ink-subtle">Refund method</dt><dd class="text-ink">{{ \App\Models\FlightBooking::statusLabel($booking->refund_method) }}</dd></div>
                        <div><dt class="text-xs text-ink-subtle">Requested</dt><dd class="text-ink">{{ $date($booking->refund_requested_at) }}</dd></div>
                        <div><dt class="text-xs text-ink-subtle">Completed</dt><dd class="text-ink">{{ $date($booking->refund_completed_at) }}</dd></div>
                    </dl>
                </x-ui.card>
            @endif
        </div>

        <div class="grid gap-4">
            <x-ui.card title="Customer">
                <dl class="grid gap-3 text-sm">
                    <div><dt class="text-xs text-ink-subtle">Name</dt><dd class="font-medium text-ink">{{ $booking->customer_name ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Email</dt><dd class="text-ink">{{ ($seesContact ? $booking->customer_email : $booking->maskedEmail()) ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Phone</dt><dd class="text-ink">{{ ($seesContact ? $booking->customer_phone : $booking->maskedPhone()) ?? '—' }}</dd></div>
                    @unless ($seesContact)
                        <p class="text-xs text-ink-subtle">Contact details are shown in full to the selling salesperson and Travel managers.</p>
                    @endunless
                </dl>
            </x-ui.card>

            <x-ui.card title="Amounts">
                <dl class="grid gap-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-ink-muted">Fare</dt><dd class="tabular text-ink">{{ $money($booking->fare_amount) }}</dd></div>
                    @if ($seesMoney)
                        <div class="flex justify-between gap-3"><dt class="text-ink-muted">Tourlast markup</dt><dd class="tabular text-success">{{ $money($booking->markup_amount) }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-3 border-t border-line pt-2"><dt class="font-medium text-ink">Total</dt><dd class="tabular font-bold text-ink">{{ $money($booking->total_amount) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-muted">Booked</dt><dd class="text-ink">{{ $date($booking->booked_at) }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Attribution">
                <dl class="grid gap-3 text-sm">
                    <div><dt class="text-xs text-ink-subtle">Salesperson</dt><dd class="text-ink">{{ $booking->salesperson?->name ?? 'Not attributed' }}</dd></div>
                    <div>
                        <dt class="text-xs text-ink-subtle">Promo / influencer code</dt>
                        <dd class="text-ink">
                            @if ($booking->promo_code)
                                <span class="font-mono">{{ $booking->promo_code }}</span>
                                @if ($booking->influencerCode)
                                    <span class="text-ink-muted">· {{ $booking->influencerCode->influencer?->name }}</span>
                                @else
                                    <span class="text-xs text-ink-subtle">· not a running flights code</span>
                                @endif
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Sync">
                <dl class="grid gap-3 text-sm">
                    <div><dt class="text-xs text-ink-subtle">Source</dt><dd class="text-ink">{{ $booking->source_system }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Flights booking ID</dt><dd class="font-mono text-ink">{{ $booking->external_id }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Last synced</dt><dd class="text-ink">{{ $booking->last_synced_at ? $booking->last_synced_at->format('j M Y, H:i').' ('.$booking->last_synced_at->diffForHumans().')' : '—' }}</dd></div>
                    <div><dt class="text-xs text-ink-subtle">Sync status</dt><dd><x-ui.pill :tone="$booking->sync_status === 'synced' ? 'success' : 'warning'">{{ ucfirst($booking->sync_status) }}</x-ui.pill></dd></div>
                    @if ($managesAll && $booking->sync_error)
                        <div><dt class="text-xs text-ink-subtle">Sync error</dt><dd class="text-danger">{{ $booking->sync_error }}</dd></div>
                    @endif
                </dl>
            </x-ui.card>
        </div>
    </div>
</div>
