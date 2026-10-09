@php
    $state = $ticket?->stateInfo();
    $valid = $ticket && $ticket->isIssued();
    $tones = [
        'success' => 'bg-[#e7f6ec] text-[#15803d]',
        'brand' => 'bg-[#e6f0fa] text-[#0c5295]',
        'warning' => 'bg-[#fdf3e3] text-[#b45309]',
        'danger' => 'bg-[#fdeaea] text-[#b91c1c]',
        'neutral' => 'bg-[#eef2f6] text-[#44546a]',
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $ticket ? 'Booking '.$ticket->reference() : 'Booking not found' }} · Tourlast</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/tourlast-icon.svg') }}">
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-[#f4f7fb] text-[#0f1b2d] [color-scheme:light]">
<main class="mx-auto grid min-h-screen w-full max-w-md content-center gap-5 px-4 py-10">
    <x-logo-mark class="h-7 w-auto justify-self-center text-[#0c5295]" />

    <section class="overflow-hidden rounded-xl border border-[#dce5ef] bg-white shadow-[0_8px_24px_-12px_rgb(15_27_45/0.18)]">
        @if (! $ticket)
            <div class="grid justify-items-center gap-2 px-6 py-10 text-center">
                <span class="grid size-12 place-items-center rounded-full bg-[#fdeaea] text-[#b91c1c]">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </span>
                <h1 class="text-lg font-bold">Booking not found</h1>
                <p class="text-sm text-[#44546a]">This link doesn't match a Tourlast booking. Check the ticket, or contact {{ \App\Support\Travel\BookingTicket::SupportEmail }}.</p>
            </div>
        @else
            <div class="grid justify-items-center gap-2 border-b border-[#eef2f6] px-6 py-7 text-center">
                <span class="grid size-12 place-items-center rounded-full {{ $tones[$state['tone']] }}">
                    @if ($valid)
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    @else
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    @endif
                </span>
                <h1 class="text-xl font-bold tracking-tight">{{ $valid ? 'Booking verified' : $state['label'] }}</h1>
                <p class="text-[13px] text-[#44546a]">
                    @if ($valid)
                        This is a genuine Tourlast booking.
                    @elseif ($ticket->state() === 'pending')
                        This booking exists but has not been confirmed yet.
                    @else
                        This booking is not valid for travel.
                    @endif
                </p>
            </div>

            <dl class="grid gap-3 px-6 py-5 text-sm">
                @foreach (array_filter([
                    'Booking reference' => $ticket->reference(),
                    'Package' => $ticket->packageName(),
                    'Provider' => $ticket->providerName(),
                    'Date' => $ticket->dateLabel(),
                    'Guests' => $ticket->guests(),
                ]) as $name => $value)
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-[13px] text-[#6b7a90]">{{ $name }}</dt>
                        <dd class="text-right font-semibold {{ $name === 'Booking reference' ? 'font-mono tracking-wide' : '' }}">{{ $value }}</dd>
                    </div>
                @endforeach
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-[13px] text-[#6b7a90]">Status</dt>
                    <dd><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $tones[$state['tone']] }}">{{ $state['short'] }}</span></dd>
                </div>
            </dl>

            <p class="bg-[#f7f9fc] px-6 py-3 text-center text-xs text-[#6b7a90]">Status checked {{ now()->format('j M Y, H:i') }} (live).</p>
        @endif
    </section>

    <p class="text-center text-xs text-[#6b7a90]">Questions? {{ \App\Support\Travel\BookingTicket::SupportEmail }}</p>
</main>
</body>
</html>
