@php
    $kes = fn ($value) => 'KES '.number_format((float) $value);
    $bookingUrl = fn (int $id) => \Illuminate\Support\Facades\Route::has('travel.bookings.show') ? route('travel.bookings.show', $id) : null;
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="Payments" description="Package payments: M-Pesa prompts and paybill payments from Daraja, cash and bank payments awaiting Accounts, and paybill money that matched no booking.">
        <x-slot:actions>
            <x-ui.segmented wire:model.live="tab" :options="$tabs" />
        </x-slot:actions>
    </x-ui.page-header>

    @if ($simulated)
        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-warning/40 bg-warning-soft/50 px-4 py-2.5 text-[13px] text-ink">
            <x-ui.icon name="alert" class="size-4 text-warning" />
            M-Pesa is in test mode — no real money moves. Payments here come from the simulator.
        </div>
    @endif

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card {{ $seesAll ? 'md:grid-cols-4' : 'md:grid-cols-3' }}">
        @foreach (array_filter([
            ['Collected this month', $kes($summary['collected']), 'text-success'],
            ['Waiting for customer PIN', number_format($summary['pending']), 'text-ink'],
            ['Awaiting Accounts', number_format($summary['awaiting']), $summary['awaiting'] ? 'text-warning' : 'text-ink'],
            $seesAll ? ['Unmatched M-Pesa', $summary['unmatched'] ? number_format($summary['unmatched']).' · '.$kes($summary['unmatched_amount']) : '0', $summary['unmatched'] ? 'text-danger' : 'text-ink'] : null,
        ]) as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular truncate text-xl leading-tight font-bold {{ $tone }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($tab === 'callbacks')
        <x-ui.table-card :paginator="$callbacks">
            <table class="w-full min-w-[960px] text-sm">
                <thead class="text-left text-ink-subtle uppercase">
                    <tr>
                        <th>Received</th>
                        <th>Type</th>
                        <th>Payment</th>
                        <th>Result</th>
                        <th>From</th>
                        <th>Payload</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($callbacks as $callback)
                        <tr wire:key="cb-{{ $callback->id }}">
                            <td class="whitespace-nowrap text-ink-muted">{{ $callback->created_at->format('j M Y, H:i:s') }}</td>
                            <td class="whitespace-nowrap text-ink">{{ str_replace('_', ' ', $callback->type) }}</td>
                            <td class="whitespace-nowrap text-ink-muted">{{ $callback->payment?->mpesa_receipt ?? ($callback->travel_payment_id ? '#'.$callback->travel_payment_id : '—') }}</td>
                            <td>
                                @if ($callback->error)
                                    <x-ui.pill tone="danger">{{ \Illuminate\Support\Str::limit($callback->error, 60) }}</x-ui.pill>
                                @elseif ($callback->processed_at)
                                    <x-ui.pill tone="success">Processed</x-ui.pill>
                                @else
                                    <x-ui.pill tone="warning">Not processed</x-ui.pill>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-xs text-ink-subtle">{{ $callback->ip_address ?? '—' }}</td>
                            <td class="max-w-md">
                                <details>
                                    <summary class="cursor-pointer text-xs text-brand-text">Show</summary>
                                    <pre class="mt-1 max-h-60 overflow-auto rounded bg-surface-muted p-2 text-[11px] text-ink-muted">{{ json_encode($callback->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state icon="wallet" title="No callbacks yet" description="Messages from Safaricom appear here as they arrive." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.table-card>
    @else
        <div class="flex flex-wrap items-end gap-2 rounded-xl border border-line bg-surface p-3 shadow-card">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Receipt, reference, booking or client" />
            <div class="w-36">
                <x-ui.select wire:model.live="method" id="filter-method" aria-label="Method">
                    <option value="">All methods</option>
                    @foreach ($methods as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="w-36">
                <x-ui.select wire:model.live="status" id="filter-status" aria-label="Status">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            @if ($seesAll)
                <div class="w-44">
                    <x-ui.select wire:model.live="salesperson" id="filter-salesperson" aria-label="Salesperson">
                        <option value="">All salespeople</option>
                        @foreach ($salespeople as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            @endif
            <div class="w-36"><x-ui.input type="date" wire:model.live="from" id="filter-from" aria-label="From" /></div>
            <div class="w-36"><x-ui.input type="date" wire:model.live="to" id="filter-to" aria-label="To" /></div>
        </div>

        <x-ui.table-card :paginator="$payments">
            <table class="w-full min-w-[1180px] text-sm">
                <thead class="text-left text-ink-subtle uppercase">
                    <tr>
                        <th>Receipt / reference</th>
                        <th>Booking</th>
                        <th>Client</th>
                        <th>Package</th>
                        <th>Method</th>
                        <th class="text-right">Amount</th>
                        <th>Status</th>
                        <th>Paid at</th>
                        <th>Recorded / confirmed</th>
                        <th class="text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($payments as $payment)
                        @php
                            $awaiting = $payment->channel === \App\Enums\Travel\PaymentChannel::Manual && $payment->status === \App\Enums\Travel\PaymentStatus::Completed && ! $payment->confirmed_at;
                        @endphp
                        <tr wire:key="pay-{{ $payment->id }}">
                            <td class="whitespace-nowrap">
                                <span class="grid leading-tight">
                                    <span class="font-medium text-ink">{{ $payment->mpesa_receipt ?? $payment->reference ?? '—' }}</span>
                                    <span class="text-xs text-ink-subtle">Account: {{ $payment->account_reference ?? '—' }}</span>
                                </span>
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($payment->booking)
                                    @if ($url = $bookingUrl($payment->booking->id))
                                        <a href="{{ $url }}" wire:navigate class="font-medium text-brand-text hover:underline">{{ $payment->booking->reference }}</a>
                                    @else
                                        <span class="font-medium text-ink">{{ $payment->booking->reference }}</span>
                                    @endif
                                @else
                                    <x-ui.pill tone="danger">Unmatched</x-ui.pill>
                                @endif
                            </td>
                            <td class="max-w-48 truncate text-ink">{{ $payment->booking?->client?->name ?? $payment->payer_name ?? '—' }}</td>
                            <td class="max-w-56 truncate text-ink-muted">{{ $payment->booking?->package?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap text-ink-muted">{{ $payment->method->label() }} <span class="text-xs text-ink-subtle">· {{ $payment->channel->label() }}</span></td>
                            <td class="tabular whitespace-nowrap text-right font-medium text-ink">{{ $kes($payment->amount) }}</td>
                            <td class="whitespace-nowrap">
                                @if ($awaiting)
                                    <x-ui.pill tone="warning">Awaiting Accounts</x-ui.pill>
                                @else
                                    <x-ui.pill :tone="$payment->status->tone()">{{ $payment->status->label() }}</x-ui.pill>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-ink-muted">{{ ($payment->paid_at ?? $payment->created_at)->format('j M Y, H:i') }}</td>
                            <td class="whitespace-nowrap text-xs text-ink-muted">
                                {{ $payment->recorder?->name ?? 'M-Pesa' }}
                                @if ($payment->confirmer) · <span class="text-success">✓ {{ $payment->confirmer->name }}</span> @endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                @if ($confirms && $awaiting)
                                    <x-ui.button size="sm" icon="check" wire:click="confirm({{ $payment->id }})" wire:confirm="Confirm that {{ $kes($payment->amount) }} was received?">Confirm</x-ui.button>
                                    <x-ui.button size="sm" variant="danger-ghost" wire:click="openReject({{ $payment->id }})">Reject</x-ui.button>
                                @elseif ($confirms && ! $payment->package_booking_id && $payment->status === \App\Enums\Travel\PaymentStatus::Completed)
                                    <x-ui.button size="sm" wire:click="openAllocate({{ $payment->id }})">Allocate</x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><x-ui.empty-state icon="wallet" title="No payments found" description="Payments appear here as clients pay by M-Pesa or staff log cash and bank payments." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.table-card>
    @endif

    <x-ui.modal wire:model="showAllocate" title="Allocate M-Pesa payment" maxWidth="max-w-lg"
        description="{{ $allocating ? $kes($allocating->amount).' · '.$allocating->mpesa_receipt.' · account “'.($allocating->account_reference ?? '—').'”' : '' }}">
        <form id="allocate-form" wire:submit="allocate" class="grid gap-3">
            <x-ui.search wire:model.live.debounce.300ms="bookingSearch" placeholder="Booking reference or client name" :wide="true" />
            <div class="grid max-h-72 gap-1 overflow-y-auto">
                @forelse ($bookingOptions as $option)
                    <label wire:key="alloc-{{ $option->id }}" class="flex cursor-pointer items-center gap-3 rounded-md border border-line px-3 py-2 text-[13px] has-checked:border-brand has-checked:bg-brand-soft/40">
                        <input type="radio" wire:model="allocateBookingId" value="{{ $option->id }}" class="accent-brand">
                        <span class="grid min-w-0 leading-tight">
                            <span class="font-medium text-ink">{{ $option->reference }} · {{ $option->client?->name }}</span>
                            <span class="truncate text-xs text-ink-subtle">{{ $option->package?->name }} · balance {{ $kes($option->balance()) }}</span>
                        </span>
                    </label>
                @empty
                    <p class="px-1 py-2 text-xs text-ink-subtle">No open bookings match.</p>
                @endforelse
            </div>
            @error('allocateBookingId') <p class="text-xs text-danger">{{ $message }}</p> @enderror
            <p class="text-xs text-ink-subtle">Allocation is final and recorded in the audit log.</p>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="allocate-form">Allocate</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showReject" title="Reject payment" description="The payment will not count towards the booking. The salesperson sees your reason.">
        <form id="reject-form" wire:submit="reject" class="grid gap-4">
            <x-ui.input label="Reason" wire:model="rejectReason" id="index-reject-reason" placeholder="e.g. Not on the bank statement" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="reject-form" variant="danger">Reject payment</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
