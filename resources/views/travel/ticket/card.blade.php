{{--
    The confirmation ticket (web). Expects $ticket (App\Support\Travel\BookingTicket).
    Always light, whatever the app theme: it's a customer document.
--}}
@php
    $state = $ticket->stateInfo();
    $payment = $ticket->payment();
    $driver = $ticket->driver();
    $guide = $ticket->guide();
    $image = $ticket->imageUrl();
    $confirmed = in_array($ticket->state(), ['confirmed', 'completed'], true);
    $tone = [
        'success' => ['text' => 'text-[#15803d]', 'pill' => 'bg-[#e7f6ec] text-[#15803d]'],
        'brand' => ['text' => 'text-[#0c5295]', 'pill' => 'bg-[#e6f0fa] text-[#0c5295]'],
        'warning' => ['text' => 'text-[#b45309]', 'pill' => 'bg-[#fdf3e3] text-[#b45309]'],
        'danger' => ['text' => 'text-[#b91c1c]', 'pill' => 'bg-[#fdeaea] text-[#b91c1c]'],
        'neutral' => ['text' => 'text-[#44546a]', 'pill' => 'bg-[#eef2f6] text-[#44546a]'],
    ];
    $icons = [
        'date' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
        'time' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'place' => 'M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z',
        'guests' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
        'driver' => 'M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12',
        'guide' => 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
        'reference' => 'M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z',
        'booker' => 'M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    ];
    $cells = [
        ['date', 'Date', $ticket->dateShort(), $ticket->weekday()],
        ['time', 'Pick-up time', $ticket->pickupTime(), $ticket->duration()],
        ['place', 'Pick-up location', $ticket->pickupLocation(), $ticket->destination()],
        ['guests', 'Guests', $ticket->guests(), null],
        ['driver', 'Driver', $driver['name'], $driver['detail']],
        ['guide', 'Guide', $guide['name'], $guide['detail']],
    ];
@endphp

<article id="tourlast-ticket" class="ticket relative mx-auto flex w-full max-w-[760px] flex-col overflow-hidden rounded-2xl border border-[#dce5ef] bg-white text-[#0f1b2d] shadow-[0_1px_2px_rgb(15_27_45/0.06),0_12px_32px_-14px_rgb(15_27_45/0.28)] [color-scheme:light] sm:flex-row">
    {{-- Main --}}
    <div class="grid min-w-0 flex-1 content-start gap-3 px-5 pt-4 pb-3">
        <header class="flex items-start justify-between gap-3">
            <span class="grid gap-0.5">
                <x-logo-mark class="h-[20px] w-auto text-[#0c5295]" />
                <span class="hidden text-[9.5px] font-medium tracking-[0.14em] text-[#6b7a90] uppercase sm:block">Tours · Experiences · Travel</span>
            </span>
            <span class="grid justify-items-end gap-0.5 text-right">
                <span class="inline-flex items-center gap-1 text-[11px] font-bold tracking-[0.08em] whitespace-nowrap uppercase {{ $tone[$state['tone']]['text'] }}">
                    {{ $state['label'] }}
                    @if ($confirmed)
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd"/></svg>
                    @endif
                </span>
                <span class="text-[10.5px] text-[#6b7a90]">Booking ticket</span>
            </span>
        </header>

        <div class="grid gap-0.5">
            <h2 class="text-[19px] leading-tight font-bold tracking-tight text-balance">{{ $ticket->packageName() }}</h2>
            <p class="truncate text-xs text-[#44546a]">{{ collect([$ticket->providerName(), $ticket->destination()])->filter()->implode(' · ') }}</p>
        </div>

        <dl class="grid grid-cols-2 gap-x-3 gap-y-2.5 border-y border-[#eef2f6] py-3 sm:grid-cols-3">
            @foreach ($cells as [$icon, $name, $value, $sub])
                <div class="flex min-w-0 gap-2">
                    <svg class="mt-px size-4 shrink-0 text-[#0c5295]" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$icon] }}"/></svg>
                    <div class="grid min-w-0 leading-tight">
                        <dt class="text-[10px] text-[#6b7a90]">{{ $name }}</dt>
                        <dd class="line-clamp-2 text-[12.5px] font-semibold" title="{{ $value }}">{{ $value }}</dd>
                        @if ($sub)<dd class="truncate text-[10.5px] text-[#6b7a90]">{{ $sub }}</dd>@endif
                    </div>
                </div>
            @endforeach
        </dl>

        <div class="flex items-center gap-3">
            <div class="grid min-w-0 flex-1 grid-cols-1 gap-2.5 sm:grid-cols-2">
                <div class="flex min-w-0 gap-2" x-data="{ copied: false }">
                    <svg class="mt-px size-4 shrink-0 text-[#0c5295]" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['reference'] }}"/></svg>
                    <div class="grid min-w-0 leading-tight">
                        <span class="text-[10px] text-[#6b7a90]">Booking reference</span>
                        <span class="flex items-center gap-1">
                            <span class="font-mono text-[13.5px] font-bold tracking-wide">{{ $ticket->reference() }}</span>
                            <button type="button" class="ticket-control grid size-5 place-items-center rounded text-[#6b7a90] hover:bg-[#eef2f6] hover:text-[#0c5295]"
                                x-on:click="navigator.clipboard?.writeText(@js($ticket->reference())); copied = true; setTimeout(() => copied = false, 1500)"
                                x-bind:aria-label="copied ? 'Copied' : 'Copy booking reference'">
                                <svg x-show="! copied" class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75m8.25-3.375H9.375c-.621 0-1.125.504-1.125 1.125v12.75c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V8.25L15 3.375Z"/></svg>
                                <svg x-show="copied" x-cloak class="size-3.5 text-[#15803d]" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            </button>
                        </span>
                    </div>
                </div>
                <div class="flex min-w-0 gap-2">
                    <svg class="mt-px size-4 shrink-0 text-[#0c5295]" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['booker'] }}"/></svg>
                    <div class="grid min-w-0 leading-tight">
                        <span class="text-[10px] text-[#6b7a90]">Booked by</span>
                        <span class="truncate text-[12.5px] font-semibold">{{ $ticket->leadGuest() }}</span>
                        @if ($ticket->guestEmail() ?? $ticket->guestPhone())
                            <span class="truncate text-[10.5px] text-[#6b7a90]">{{ $ticket->guestEmail() ?? $ticket->guestPhone() }}</span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="grid shrink-0 justify-items-center gap-0.5">
                <img src="{{ $ticket->qrDataUri(6) }}" alt="QR code to verify booking {{ $ticket->reference() }}" class="size-[76px] [image-rendering:pixelated]">
                <span class="text-[9.5px] leading-tight text-[#6b7a90]">Scan to verify</span>
            </div>
        </div>

        <p class="text-[10.5px] leading-snug text-[#6b7a90]">
            <span class="font-semibold text-[#0f1b2d]">Thank you for choosing Tourlast!</span>
            Please present this ticket when required. Questions: {{ \App\Support\Travel\BookingTicket::SupportEmail }}
        </p>
    </div>

    {{-- Tear line with notches --}}
    <div class="relative shrink-0 sm:w-0" aria-hidden="true">
        <div class="mx-4 border-t-2 border-dashed border-[#dce5ef] sm:mx-0 sm:my-4 sm:h-[calc(100%-2rem)] sm:border-t-0 sm:border-l-2"></div>
        <span class="ticket-notch absolute top-1/2 -left-3 size-6 -translate-x-px -translate-y-1/2 rounded-full border border-[#dce5ef] bg-[#f4f7fb] sm:top-0 sm:left-0 sm:-translate-x-1/2 sm:-translate-y-[13px]"></span>
        <span class="ticket-notch absolute top-1/2 -right-3 size-6 translate-x-px -translate-y-1/2 rounded-full border border-[#dce5ef] bg-[#f4f7fb] sm:top-auto sm:right-auto sm:bottom-0 sm:left-0 sm:-translate-x-1/2 sm:translate-y-[13px]"></span>
    </div>

    {{-- Stub --}}
    <aside class="grid content-start gap-2.5 px-4 pt-3 pb-4 sm:w-[218px] sm:pt-4">
        @if ($image)
            <img src="{{ $image }}" alt="" class="h-[104px] w-full rounded-lg object-cover">
        @else
            <span class="grid h-[104px] w-full place-items-center rounded-lg bg-[#e6f0fa]" aria-hidden="true">
                <x-logo-mark class="h-5 w-auto text-[#0c5295]/60" />
            </span>
        @endif

        <p class="text-[12px] font-bold">Package details</p>
        <dl class="grid gap-1.5 text-[11px]">
            @foreach (array_filter([
                'Booking ID' => $ticket->reference(),
                'Provider' => $ticket->providerName(),
                'Driver' => $driver['name'],
                'Guide' => $guide['name'],
                $payment['balance_due'] ? 'Total amount' : 'Total paid' => $payment['balance_due'] ? $payment['total'] : $payment['paid'],
                'Balance due' => $payment['balance_due'] ? $payment['balance'] : null,
            ]) as $name => $value)
                <div class="flex items-baseline justify-between gap-2">
                    <dt class="shrink-0 text-[#6b7a90]">{{ $name }}</dt>
                    <dd class="truncate text-right font-semibold" title="{{ $value }}">{{ $value }}</dd>
                </div>
            @endforeach
            <div class="flex items-center justify-between gap-2">
                <dt class="text-[#6b7a90]">Payment</dt>
                <dd class="truncate text-right font-semibold {{ $tone[$payment['tone']]['text'] }}">{{ $payment['label'] }}</dd>
            </div>
            <div class="flex items-center justify-between gap-2">
                <dt class="text-[#6b7a90]">Status</dt>
                <dd><span class="rounded-full px-2 py-0.5 text-[10.5px] font-semibold {{ $tone[$state['tone']]['pill'] }}">{{ $state['short'] }}</span></dd>
            </div>
        </dl>

        <div class="grid justify-items-center gap-0.5 pt-1">
            <img src="{{ $ticket->barcodeDataUri() }}" alt="" class="h-9 w-full max-w-[180px] [image-rendering:pixelated]">
            <span class="font-mono text-[10px] tracking-[0.12em] text-[#44546a]">{{ $ticket->reference() }}</span>
        </div>
    </aside>
</article>
