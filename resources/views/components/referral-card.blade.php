@props(['referralCode', 'editable' => true])

@php
    $shareUrl = $referralCode->shareUrl();
    $message = "List your property on Tourlast: {$shareUrl}";
@endphp

<x-ui.card title="{{ $editable ? 'My referral link' : 'Referral link' }}" description="Permanent. Every signup through it is credited automatically.">
    <div class="grid gap-5">
        <div class="grid gap-3 rounded-xl bg-brand-soft p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="font-mono text-lg font-medium text-brand-text">{{ $referralCode->code }}</span>
                <x-ui.pill tone="success">Active</x-ui.pill>
            </div>
            <div class="flex items-center gap-2 overflow-hidden rounded-lg border border-line bg-surface px-3 py-2.5">
                <x-ui.icon name="link" class="size-4 text-ink-subtle" />
                <span class="truncate text-sm text-ink-muted" title="{{ $shareUrl }}">{{ $shareUrl }}</span>
            </div>
        </div>

        @if ($editable)
            <div class="flex flex-wrap gap-2" x-data="copyText(@js($shareUrl))">
                <x-ui.button x-on:click="copy" icon="copy" size="sm">
                    <span x-show="!copied">Copy link</span>
                    <span x-show="copied" x-cloak>Copied</span>
                </x-ui.button>
                <x-ui.button variant="secondary" size="sm" icon="chat" :href="'https://wa.me/?text='.rawurlencode($message)" target="_blank" rel="noopener">WhatsApp</x-ui.button>
                <x-ui.button variant="secondary" size="sm" icon="mail" :href="'mailto:?subject='.rawurlencode('List your property on Tourlast').'&body='.rawurlencode($message)">Email</x-ui.button>
            </div>

            <div class="flex items-center gap-4 border-t border-line pt-4" x-data="qrCode(@js($shareUrl), @js($referralCode->code.'.png'))">
                <div class="rounded-lg border border-line bg-white p-1.5">
                    <canvas x-ref="canvas" class="size-[112px]!" aria-label="QR code for {{ $referralCode->code }}"></canvas>
                </div>
                <div class="grid gap-2">
                    <p class="text-[13px] text-ink-muted">QR code for site visits and printed material.</p>
                    <x-ui.button variant="secondary" size="sm" icon="qr" x-on:click="download" class="justify-self-start">Download PNG</x-ui.button>
                </div>
            </div>
        @endif

        <p class="text-xs text-ink-subtle">Opens tourlast.com's List Your Property page with <span class="font-mono">?ref={{ $referralCode->code }}</span> attached.</p>
    </div>
</x-ui.card>
