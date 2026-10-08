<div class="grid gap-5" x-data="{ copied: false, link: @js($ticket->verificationUrl()) }">
    {{-- Print only the ticket: drop the Hub around it (not just hide it, which keeps its space) and flatten the wrappers it sits in. --}}
    <style>
        @media print {
            @page { size: A5 landscape; margin: 6mm; }
            html, body { margin: 0 !important; padding: 0 !important; height: auto !important; min-height: 0 !important; background: #fff !important; }
            body *:not(:has(#tourlast-ticket)):not(#tourlast-ticket):not(#tourlast-ticket *) { display: none !important; }
            body *:has(#tourlast-ticket) { display: block !important; position: static !important; margin: 0 !important; padding: 0 !important; border: 0 !important; background: none !important; box-shadow: none !important; width: auto !important; max-width: none !important; height: auto !important; min-height: 0 !important; overflow: visible !important; }
            #tourlast-ticket { margin: 0 !important; width: 100% !important; max-width: none !important; box-shadow: none !important; break-inside: avoid; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            #tourlast-ticket .ticket-control { display: none !important; }
        }
    </style>

    <a href="{{ route('travel.bookings.show', $booking) }}" wire:navigate class="justify-self-start text-[13px] font-medium text-brand-text hover:underline">← Booking {{ $booking->reference }}</a>

    <x-ui.page-header title="Confirmation ticket" :description="$ticket->isIssued() ? 'Exactly what the client receives. It always shows the latest booking details; the QR code shows the live status.' : 'Preview only. The client gets a ticket once the booking is confirmed.'">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow-right" :href="route('travel.bookings.ticket.pdf', $booking)">Download PDF</x-ui.button>
            <x-ui.button variant="secondary" icon="register" x-on:click="window.print()">Print</x-ui.button>
            <x-ui.button variant="secondary" icon="link" x-on:click="navigator.clipboard?.writeText(link); copied = true; setTimeout(() => copied = false, 1600)">
                <span x-text="copied ? 'Link copied' : 'Copy verification link'">Copy verification link</span>
            </x-ui.button>
            @if ($ticket->isIssued())
                <x-ui.button variant="secondary" icon="chat" x-on:click="navigator.share ? navigator.share({ title: @js('Tourlast booking '.$ticket->reference()), text: @js($shareText), url: link }).catch(() => {}) : (navigator.clipboard?.writeText(@js($shareText)), copied = true)">Share</x-ui.button>
                <x-ui.button variant="secondary" icon="phone" :href="$whatsappUrl" target="_blank" rel="noopener">WhatsApp</x-ui.button>
            @endif
            @if ($canSend)
                <x-ui.button icon="mail" wire:click="sendToClient" wire:loading.attr="disabled" :disabled="blank($booking->client?->email)" :title="blank($booking->client?->email) ? 'The client has no email address' : null">Email to client</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @unless ($ticket->isIssued())
        <div class="flex items-center gap-2 rounded-lg border border-warning/40 bg-warning-soft/50 px-4 py-2.5 text-[13px] text-ink">
            <x-ui.icon name="alert" class="size-4 text-warning" />
            This booking is {{ strtolower($ticket->stateInfo()['short']) }}. The ticket and its QR code show that status, so it can't be passed off as confirmed.
        </div>
    @endunless

    <div class="rounded-xl bg-[#f4f7fb] px-2 py-6 sm:px-6 sm:py-10 dark:bg-surface-muted">
        @include('travel.ticket.card', ['ticket' => $ticket])
    </div>
</div>
