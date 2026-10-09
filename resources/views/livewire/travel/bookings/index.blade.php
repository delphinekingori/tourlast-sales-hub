<div class="grid gap-5">
    <x-ui.page-header title="Package bookings" description="Tour and experience bookings, with the client, travelers, amounts, payment and who runs the trip. Booking status, payment status and trip status are tracked separately.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="users" :href="route('travel.clients.index')" wire:navigate>Clients</x-ui.button>
            @if ($canBook)
                <x-ui.button icon="plus" :href="route('travel.bookings.create')" wire:navigate>New booking</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card sm:grid-cols-3 xl:grid-cols-6">
        @foreach ([
            ['Bookings', number_format($summary['total']), 'text-ink'],
            ['Pending', number_format($summary['pending']), $summary['pending'] ? 'text-warning' : 'text-ink'],
            ['Confirmed', number_format($summary['confirmed']), 'text-brand-text'],
            ['Travelers (confirmed)', number_format($summary['travelers']), 'text-ink'],
            ['Booking value', 'KES '.number_format($summary['value']), 'text-ink'],
            ['Pre-trip action required', number_format($summary['pretrip']), $summary['pretrip'] ? 'text-danger' : 'text-ink'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular truncate text-xl leading-tight font-bold {{ $tone }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="grid gap-3 rounded-xl border border-line bg-surface p-3 shadow-card">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-5">
            <div class="grid self-end sm:col-span-2 lg:col-span-1 2xl:col-span-1">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Booking ID, client or package" wide />
            </div>
            <x-ui.select label="Booking status" wire:model.live="status" id="bk-status">
                <option value="">Any</option>
                @foreach (\App\Enums\Travel\TravelBookingStatus::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
            </x-ui.select>
            <x-ui.select label="Payment status" wire:model.live="payment" id="bk-payment">
                <option value="">Any</option>
                @foreach (\App\Enums\Travel\BookingPaymentStatus::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
            </x-ui.select>
            <x-ui.select label="Package" wire:model.live="package" id="bk-package">
                <option value="">Any</option>
                @foreach ($packages as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
            </x-ui.select>
            @if ($seesAll)
                <x-ui.select label="Salesperson" wire:model.live="salesperson" id="bk-salesperson">
                    <option value="">Anyone</option>
                    @foreach ($salespeople as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                </x-ui.select>
            @endif
            <x-ui.input label="Booked from" type="date" wire:model.live="bookedFrom" id="bk-booked-from" />
            <x-ui.input label="Booked to" type="date" wire:model.live="bookedTo" id="bk-booked-to" />
            <x-ui.input label="Travel date from" type="date" wire:model.live="travelFrom" id="bk-travel-from" />
            <x-ui.input label="Travel date to" type="date" wire:model.live="travelTo" id="bk-travel-to" />
            <label class="flex items-center gap-2 self-end pb-2 text-[13px] text-ink-muted">
                <input type="checkbox" wire:model.live="pretrip" class="size-4 accent-[var(--tl-brand)]"> Pre-trip action required
            </label>
        </div>
        @if ($search !== '' || $status !== '' || $payment !== '' || $package !== '' || $salesperson !== '' || $bookedFrom !== '' || $bookedTo !== '' || $travelFrom !== '' || $travelTo !== '' || $pretrip)
            <div class="flex flex-wrap items-center gap-2 text-[13px]">
                <span class="font-medium text-ink">{{ number_format($bookings->total()) }} {{ \Illuminate\Support\Str::plural('booking', $bookings->total()) }} found</span>
                <button type="button" wire:click="clearFilters" class="font-medium text-brand-text hover:underline">Clear all</button>
            </div>
        @endif
    </div>

    <x-ui.table-card :paginator="$bookings">
        <table class="w-full min-w-[1380px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th>Booking ID</th>
                    <th class="min-w-52">Package</th>
                    <th class="min-w-36">Client</th>
                    <th>Booking date</th>
                    <th>Travel date</th>
                    <th class="text-right">Adults</th>
                    <th class="text-right">Children</th>
                    <th class="text-right">Travelers</th>
                    <th class="text-right">Amount</th>
                    <th>Payment</th>
                    <th>Booking status</th>
                    <th>Salesperson</th>
                    <th>Driver</th>
                    <th>Guide</th>
                    <th class="text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($bookings as $booking)
                    <tr wire:key="bk-{{ $booking->id }}">
                        <td class="whitespace-nowrap">
                            <a href="{{ route('travel.bookings.show', $booking) }}" wire:navigate class="font-medium text-ink hover:text-brand-text">{{ $booking->reference }}</a>
                            @if ($booking->source === \App\Enums\Travel\BookingSource::Synced)
                                <span class="block text-xs text-brand-text">Synced</span>
                            @endif
                            @isset($pretripIds[$booking->id])
                                <span class="block text-xs font-medium text-danger">Pre-trip action required</span>
                            @endisset
                        </td>
                        <td class="text-ink">{{ $booking->package->name }}</td>
                        <td class="text-ink">{{ $booking->client->name }}</td>
                        <td class="whitespace-nowrap text-ink-muted">{{ $booking->created_at->format('j M Y') }}</td>
                        <td class="whitespace-nowrap text-ink">{{ $booking->departure->dateLabel() }}</td>
                        <td class="tabular text-right">{{ $booking->adults }}</td>
                        <td class="tabular text-right">{{ $booking->children }}</td>
                        <td class="tabular text-right font-medium">{{ $booking->travelers }}</td>
                        <td class="tabular text-right whitespace-nowrap">{{ $booking->currency }} {{ number_format((float) $booking->amount_total) }}</td>
                        <td><x-ui.pill :tone="$booking->payment_status->tone()">{{ $booking->payment_status->label() }}</x-ui.pill></td>
                        <td><x-ui.pill :tone="$booking->status->tone()">{{ $booking->status->label() }}</x-ui.pill></td>
                        <td class="text-ink-muted">{{ $booking->salesperson?->name ?? '—' }}</td>
                        <td class="text-ink-muted">{{ $booking->effectiveDriver()?->name ?? '—' }}</td>
                        <td class="text-ink-muted">{{ $booking->effectiveGuide()?->name ?? '—' }}</td>
                        <td class="text-right"><x-ui.button variant="ghost" size="sm" :href="route('travel.bookings.show', $booking)" wire:navigate>Open</x-ui.button></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="15">
                            <x-ui.empty-state icon="ticket" title="No bookings found" description="Bookings you take on packages appear here." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>
</div>
