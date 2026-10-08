<div class="grid gap-5">
    <x-ui.page-header title="Clients" description="Package clients and their bookings. Clients are matched on phone or email, so each person appears once however many times they book.">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('travel.bookings.index')" wire:navigate>Bookings</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-wrap items-center gap-3">
        <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search name, email or phone" />
        <span class="text-[13px] text-ink-subtle">{{ number_format($clients->total()) }} {{ \Illuminate\Support\Str::plural('client', $clients->total()) }}</span>
    </div>

    <x-ui.table-card :paginator="$clients">
        <table class="w-full min-w-[820px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th>Client</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Country</th>
                    <th class="text-right">Bookings</th>
                    <th class="text-right">Total paid</th>
                    <th>Last booked</th>
                    <th class="text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($clients as $row)
                    <tr wire:key="cl-{{ $row->id }}">
                        <td class="font-medium text-ink">{{ $row->name }}</td>
                        <td class="text-ink-muted">{{ $row->phone ?? '—' }}</td>
                        <td class="text-ink-muted">{{ $row->email ?? '—' }}</td>
                        <td class="text-ink-muted">{{ $row->country ?? '—' }}</td>
                        <td class="tabular text-right">{{ $row->bookings_count }}</td>
                        <td class="tabular text-right">KES {{ number_format((float) $row->total_spent) }}</td>
                        <td class="text-ink-muted">{{ $row->last_booked_at ? \Illuminate\Support\Carbon::parse($row->last_booked_at)->format('j M Y') : '—' }}</td>
                        <td class="text-right"><x-ui.button variant="ghost" size="sm" wire:click="open({{ $row->id }})">View</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-ui.empty-state icon="users" title="No clients yet" description="Clients are added when you take a booking." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    <x-ui.slide-over wire:model="showClient" :title="$client?->name ?? 'Client'" :description="$client ? collect([$client->phone, $client->email, $client->country])->filter()->implode(' · ') : null">
        @if ($client)
            <div class="grid gap-3">
                @if ($client->notes)<p class="text-[13px] text-ink-muted">{{ $client->notes }}</p>@endif
                <h3 class="text-xs font-semibold tracking-wide text-ink-subtle uppercase">Bookings</h3>
                <ul class="divide-y divide-line rounded-lg border border-line text-[13px]">
                    @forelse ($clientBookings as $booking)
                        <li wire:key="clb-{{ $booking->id }}">
                            <a href="{{ route('travel.bookings.show', $booking) }}" wire:navigate class="flex items-center justify-between gap-3 px-3 py-2 hover:bg-surface-muted/50">
                                <span class="grid leading-tight">
                                    <span class="font-medium text-ink">{{ $booking->package->name }}</span>
                                    <span class="text-xs text-ink-subtle">{{ $booking->reference }} · {{ $booking->departure->dateLabel() }} · {{ $booking->travelers }} travelers</span>
                                </span>
                                <x-ui.pill :tone="$booking->status->tone()" :dot="false">{{ $booking->status->label() }}</x-ui.pill>
                            </a>
                        </li>
                    @empty
                        <li class="px-3 py-4 text-center text-ink-subtle">No bookings you can see.</li>
                    @endforelse
                </ul>
            </div>
        @endif
    </x-ui.slide-over>
</div>
