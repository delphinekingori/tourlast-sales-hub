@props(['referralCode', 'canvasClass' => 'size-[112px]!'])

{{-- Needs a parent scope with x-data="{ target: 'stays' }". One QR per link; the one for the selected link shows. --}}
@foreach (\App\Enums\ReferralTarget::cases() as $option)
    <div class="grid content-start justify-items-center gap-2" x-show="target === '{{ $option->value }}'" @if (! $loop->first) x-cloak @endif
        x-data="qrCode(@js($referralCode->shareUrl($option)), @js($referralCode->code.'-'.$option->value.'.png'))">
        <div class="rounded-lg border border-line bg-white p-1.5">
            <canvas x-ref="canvas" class="{{ $canvasClass }}" aria-label="QR code for the {{ $option->label() }} link of {{ $referralCode->code }}"></canvas>
        </div>
        <x-ui.button variant="ghost" size="sm" icon="qr" x-on:click="download">Download QR</x-ui.button>
    </div>
@endforeach
