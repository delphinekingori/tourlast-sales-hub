@php
    $state = $ticket->stateInfo();
    $payment = $ticket->payment();
    $driver = $ticket->driver();
    $guide = $ticket->guide();
    $colors = ['success' => ['#E7F6EC', '#15803D'], 'brand' => ['#E6F0FA', '#0C5295'], 'warning' => ['#FDF3E3', '#B45309'], 'danger' => ['#FDEAEA', '#B91C1C'], 'neutral' => ['#EEF2F6', '#44546A']];
    [$stateBg, $stateFg] = $colors[$state['tone']];
    $confirmed = in_array($ticket->state(), ['confirmed', 'completed'], true);
    $cells = [
        [['Date', $ticket->dateShort(), $ticket->weekday()], ['Pick-up time', $ticket->pickupTime(), $ticket->duration()], ['Pick-up location', $ticket->pickupLocation(), $ticket->destination()]],
        [['Guests', $ticket->guests(), null], ['Driver', $driver['name'], $driver['detail']], ['Guide', $guide['name'], $guide['detail']]],
    ];
    $details = array_filter([
        'Booking ID' => $ticket->reference(),
        'Provider' => $ticket->providerName(),
        'Driver' => $driver['name'],
        'Guide' => $guide['name'],
        $payment['balance_due'] ? 'Total amount' : 'Total paid' => $payment['balance_due'] ? $payment['total'] : $payment['paid'],
        'Balance due' => $payment['balance_due'] ? $payment['balance'] : null,
    ]);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $ticket->reference() }} · Tourlast ticket</title>
<style>
    @page { margin: 5mm; }
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; color: #0F1B2D; font-size: 7.6pt; margin: 0; }
    .ticket { position: relative; border: 0.7pt solid #DCE5EF; border-radius: 9pt; }
    table { border-collapse: collapse; width: 100%; }
    td { vertical-align: top; padding: 0; }
    .label { font-size: 5.8pt; color: #6B7A90; }
    .value { font-size: 7.8pt; font-weight: bold; margin-top: 0.4mm; }
    .sub { font-size: 6.2pt; color: #6B7A90; margin-top: 0.3mm; }
    .rule { border-top: 0.6pt solid #EEF2F6; }
    .dot { display: inline-block; width: 2.6mm; height: 2.6mm; border: 0.9pt solid #0C5295; border-radius: 1.6mm; margin-top: 0.4mm; }
</style>
</head>
<body>
<div class="ticket">
    <table>
        <tr>
            {{-- Main --}}
            <td style="padding: 4mm 5mm 3.2mm;">
                <table>
                    <tr>
                        <td>
                            <img src="data:image/png;base64,{{ $logo }}" style="height: 4.6mm;" alt="Tourlast">
                            <div style="font-size: 5.6pt; letter-spacing: 0.9pt; color: #6B7A90; margin-top: 0.6mm;">TOURS · EXPERIENCES · TRAVEL</div>
                        </td>
                        <td style="text-align: right;">
                            <div style="font-size: 7.2pt; font-weight: bold; letter-spacing: 0.6pt; color: {{ $stateFg }};">{{ strtoupper($state['label']) }}{{ $confirmed ? ' ✓' : '' }}</div>
                            <div class="sub">Booking ticket</div>
                        </td>
                    </tr>
                </table>

                <div style="font-size: 12.5pt; font-weight: bold; margin-top: 2.6mm; line-height: 1.15;">{{ $ticket->packageName() }}</div>
                <div style="font-size: 7pt; color: #44546A; margin-top: 0.8mm;">{{ collect([$ticket->providerName(), $ticket->destination()])->filter()->implode(' · ') }}</div>

                <div class="rule" style="margin-top: 2.6mm;"></div>
                <table style="margin-top: 2.2mm;">
                    @foreach ($cells as $row)
                        <tr>
                            @foreach ($row as [$name, $value, $sub])
                                <td style="width: 33.3%; padding-bottom: 2mm;">
                                    <table>
                                        <tr>
                                            <td style="width: 4mm;"><span class="dot"></span></td>
                                            <td>
                                                <div class="label">{{ $name }}</div>
                                                <div class="value">{{ \Illuminate\Support\Str::limit($value, 34) }}</div>
                                                @if ($sub)<div class="sub">{{ \Illuminate\Support\Str::limit($sub, 34) }}</div>@endif
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
                @if ($ticket->guestNames())
                    <div style="font-size: 6.4pt; color: #44546A; margin: -0.6mm 0 1.6mm;"><span class="label">Guest names:</span> {{ \Illuminate\Support\Str::limit($ticket->guestNames(), 150) }}</div>
                @endif
                <div class="rule"></div>

                <table style="margin-top: 2.4mm;">
                    <tr>
                        <td style="width: 38%;">
                            <div class="label">Booking reference</div>
                            <div style="font-family: DejaVu Sans Mono, monospace; font-size: 9.6pt; font-weight: bold; margin-top: 0.6mm;">{{ $ticket->reference() }}</div>
                        </td>
                        <td>
                            <div class="label">Booked by</div>
                            <div class="value">{{ $ticket->leadGuest() }}</div>
                            @if ($ticket->guestEmail() ?? $ticket->guestPhone())<div class="sub">{{ $ticket->guestEmail() ?? $ticket->guestPhone() }}</div>@endif
                        </td>
                        <td style="width: 21mm; text-align: center;">
                            <img src="{{ $qr }}" style="width: 19mm; height: 19mm;" alt="QR code">
                            <div class="sub" style="margin-top: 0;">Scan to verify</div>
                        </td>
                    </tr>
                </table>

                <div style="font-size: 6.4pt; color: #6B7A90; margin-top: 1.6mm;">
                    <strong style="color: #0F1B2D;">Thank you for choosing Tourlast!</strong> Please present this ticket when required. Questions: {{ \App\Support\Travel\BookingTicket::SupportEmail }}
                </div>
            </td>

            {{-- Stub --}}
            <td style="width: 58mm; padding: 4mm 4mm 3.2mm; border-left: 1.1pt dashed #C9D6E4;">
                @if ($image)
                    <img src="{{ $image }}" style="width: 50mm; height: 24mm; border-radius: 4pt;" alt="">
                @else
                    <div style="width: 50mm; height: 24mm; border-radius: 4pt; background: #E6F0FA; text-align: center;">
                        <img src="data:image/png;base64,{{ $logo }}" style="height: 3.6mm; margin-top: 10mm; opacity: 0.5;" alt="">
                    </div>
                @endif

                <div style="font-size: 8pt; font-weight: bold; margin: 2.4mm 0 1.4mm;">Package details</div>
                <table>
                    @foreach ($details as $name => $value)
                        <tr>
                            <td style="font-size: 6.6pt; color: #6B7A90; padding-bottom: 1.1mm;">{{ $name }}</td>
                            <td style="font-size: 6.6pt; font-weight: bold; text-align: right; padding-bottom: 1.1mm;">{{ \Illuminate\Support\Str::limit($value, 24) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td style="font-size: 6.6pt; color: #6B7A90; padding-bottom: 1.1mm;">Payment</td>
                        <td style="font-size: 6.6pt; font-weight: bold; text-align: right; padding-bottom: 1.1mm; color: {{ $colors[$payment['tone']][1] }};">{{ $payment['label'] }}</td>
                    </tr>
                    <tr>
                        <td style="font-size: 6.6pt; color: #6B7A90;">Status</td>
                        <td style="text-align: right;"><span style="background: {{ $stateBg }}; color: {{ $stateFg }}; font-size: 6.2pt; font-weight: bold; padding: 0.5mm 2mm; border-radius: 5pt;">{{ $state['short'] }}</span></td>
                    </tr>
                </table>

                <div style="text-align: center; margin-top: 2.4mm;">
                    <img src="{{ $barcode }}" style="width: 44mm; height: 8mm;" alt="">
                    <div style="font-family: DejaVu Sans Mono, monospace; font-size: 6pt; letter-spacing: 1pt; color: #44546A;">{{ $ticket->reference() }}</div>
                </div>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
