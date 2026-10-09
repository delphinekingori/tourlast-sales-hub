@php
    $money = fn ($value) => ($booking->currency ?: 'KES').' '.number_format((float) $value);
@endphp

<div @if ($hasPending) wire:poll.3s @endif>
    <x-ui.card title="Payments" :padding="false"
        description="Paid {{ $money($booking->amount_paid) }} of {{ $money($booking->amount_total) }}{{ (float) $booking->amount_refunded > 0 ? ' · refunded '.$money($booking->amount_refunded) : '' }}">
        <x-slot:actions>
            <x-ui.pill :tone="$booking->payment_status->tone()">{{ $booking->payment_status->label() }}</x-ui.pill>
            @if ($canCollect)
                <x-ui.button size="sm" variant="secondary" wire:click="openManual">Log cash/bank payment</x-ui.button>
                <x-ui.button size="sm" icon="phone" wire:click="openRequest">Request M-Pesa payment</x-ui.button>
            @endif
        </x-slot:actions>

        @if ($simulated)
            <p class="flex items-center gap-2 border-b border-line bg-warning-soft/50 px-4 py-2 text-xs text-ink">
                <x-ui.icon name="alert" class="size-4 text-warning" />
                M-Pesa is in test mode — no real money moves.
            </p>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-2.5 text-[13px]">
            <span class="text-ink-muted">Balance due</span>
            <span class="tabular text-base font-bold {{ $booking->balance() > 0 ? 'text-ink' : 'text-success' }}">{{ $money($booking->balance()) }}</span>
        </div>

        @forelse ($payments as $payment)
            @php
                $awaiting = $payment->channel === \App\Enums\Travel\PaymentChannel::Manual && $payment->status === \App\Enums\Travel\PaymentStatus::Completed && ! $payment->confirmed_at;
                $pendingPrompt = $payment->channel === \App\Enums\Travel\PaymentChannel::Stk && $payment->status === \App\Enums\Travel\PaymentStatus::Pending;
            @endphp
            <div wire:key="booking-payment-{{ $payment->id }}" class="grid gap-1.5 border-b border-line px-4 py-2.5 last:border-b-0">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="grid min-w-0 leading-tight">
                        <span class="text-[13px] font-medium text-ink">
                            {{ $money($payment->amount) }}
                            <span class="font-normal text-ink-subtle">· {{ $payment->method->label() }} · {{ $payment->channel->label() }}</span>
                        </span>
                        <span class="truncate text-xs text-ink-subtle">
                            {{ $payment->mpesa_receipt ?? $payment->reference ?? 'No receipt yet' }}
                            @if ($payment->phone) · {{ \App\Models\TravelClient::mask($payment->phone) }} @endif
                            · {{ ($payment->paid_at ?? $payment->created_at)->format('j M Y, H:i') }}
                            @if ($payment->recorder) · by {{ $payment->recorder->name }} @endif
                        </span>
                    </span>
                    <span class="flex items-center gap-2">
                        @if ($awaiting)
                            <x-ui.pill tone="warning">Awaiting Accounts</x-ui.pill>
                        @elseif ($pendingPrompt)
                            <x-ui.pill tone="warning">Waiting for PIN</x-ui.pill>
                        @else
                            <x-ui.pill :tone="$payment->status->tone()">{{ $payment->status->label() }}</x-ui.pill>
                        @endif
                    </span>
                </div>

                @if ($payment->result_description && $payment->status !== \App\Enums\Travel\PaymentStatus::Completed)
                    <p class="text-xs text-ink-muted">{{ $payment->result_description }}</p>
                @endif
                @if ($payment->confirmer)
                    <p class="text-xs text-ink-subtle">Confirmed by {{ $payment->confirmer->name }} {{ $payment->confirmed_at->diffForHumans() }}</p>
                @endif
                @if ($payment->notes)
                    <p class="text-xs whitespace-pre-line text-ink-muted">{{ $payment->notes }}</p>
                @endif

                @if ($pendingPrompt && $simulated && $canCollect)
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button size="sm" variant="secondary" wire:click="simulate({{ $payment->id }}, true)">Simulate customer paying</x-ui.button>
                        <x-ui.button size="sm" variant="danger-ghost" wire:click="simulate({{ $payment->id }}, false)">Simulate customer cancelling</x-ui.button>
                    </div>
                @endif
                @if ($awaiting && $confirms)
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button size="sm" icon="check" wire:click="confirm({{ $payment->id }})" wire:confirm="Confirm that {{ $money($payment->amount) }} was received?">Confirm</x-ui.button>
                        <x-ui.button size="sm" variant="danger-ghost" wire:click="openReject({{ $payment->id }})">Reject</x-ui.button>
                    </div>
                @endif
            </div>
        @empty
            <x-ui.empty-state icon="wallet" title="No payments yet" description="Send the client an M-Pesa prompt, or they can pay paybill {{ config('travel.mpesa.shortcode') }} with account number {{ $booking->reference }}." />
        @endforelse
    </x-ui.card>

    <x-ui.modal wire:model="showRequest" title="Request M-Pesa payment" description="The client gets a prompt on their phone and enters their M-Pesa PIN. This page updates when they pay.">
        <form id="mpesa-request-form" wire:submit="requestPayment" class="grid gap-4">
            <x-ui.input label="Client's M-Pesa number" type="tel" wire:model="phone" id="mpesa-phone" placeholder="0712 345 678" autocomplete="off" />
            <x-ui.input label="Amount (KES, whole shillings)" type="number" min="1" step="1" wire:model="amount" id="mpesa-amount" hint="Balance due: {{ $money($booking->balance()) }}" />
            <p class="text-xs text-ink-subtle">Account number on the client's statement: <span class="font-medium text-ink">{{ $booking->reference }}</span></p>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="mpesa-request-form" icon="phone">Send prompt</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showManual" title="Log a cash or bank payment" description="It counts towards the booking once Accounts confirms it.">
        <form id="manual-payment-form" wire:submit="logManual" class="grid gap-4 sm:grid-cols-2">
            <x-ui.select label="Method" wire:model.live="manual.method" id="manual-method">
                @foreach ($methods as $method)
                    <option value="{{ $method->value }}">{{ $method->label() }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="Amount (KES)" type="number" min="1" step="0.01" wire:model="manual.amount" id="manual-amount" />
            <x-ui.input label="{{ $manual['method'] === 'cash' ? 'Receipt number (optional)' : 'Bank / card reference' }}" wire:model="manual.reference" id="manual-reference" />
            <x-ui.input label="Date paid" type="date" wire:model="manual.paid_on" id="manual-paid-on" max="{{ today()->toDateString() }}" />
            <div class="sm:col-span-2">
                <x-ui.input label="Note" wire:model="manual.notes" id="manual-notes" placeholder="Optional" />
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="manual-payment-form">Log payment</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showReject" title="Reject payment" description="The payment will not count towards the booking. The salesperson sees your reason.">
        <form id="reject-payment-form" wire:submit="reject" class="grid gap-4">
            <x-ui.input label="Reason" wire:model="rejectReason" id="reject-reason" placeholder="e.g. Not on the bank statement" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="reject-payment-form" variant="danger">Reject payment</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
