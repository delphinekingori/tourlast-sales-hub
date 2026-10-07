@props(['referralCode', 'editable' => true])

<x-ui.card title="{{ $editable ? 'My referral link' : 'Referral link' }}" description="Permanent. Every signup through it is credited automatically.">
    <div class="grid gap-5" x-data="{ target: 'stays' }">
        <div class="grid gap-3 rounded-xl bg-brand-soft p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="font-mono text-lg font-medium text-brand-text">{{ $referralCode->code }}</span>
                <x-ui.pill tone="success">Active</x-ui.pill>
            </div>
            <x-referral-links :referral-code="$referralCode" :editable="$editable" size="sm" />
        </div>

        @if ($editable)
            <div class="flex items-center gap-4 border-t border-line pt-4">
                <x-referral-qr :referral-code="$referralCode" />
                <p class="text-[13px] text-ink-muted">QR code for the selected link, for site visits and printed material.</p>
            </div>
        @endif

        <p class="text-xs text-ink-subtle">Opens the registration page for Stays or Experiences with <span class="font-mono">?ref={{ $referralCode->code }}</span> attached.</p>
    </div>
</x-ui.card>
