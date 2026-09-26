<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Tourlast Partner Onboarding Report</title>
<style>
    @page { margin: 28px 32px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #0F1B2D; }
    h1 { font-size: 18px; margin: 0; color: #0C5295; }
    h2 { font-size: 12px; margin: 18px 0 8px; color: #0C5295; text-transform: uppercase; letter-spacing: .06em; }
    .muted { color: #6B7A90; }
    .header { border-bottom: 3px solid #0C5295; padding-bottom: 10px; margin-bottom: 14px; }
    .header td { vertical-align: middle; }
    table { width: 100%; border-collapse: collapse; }
    .grid td { padding: 6px 8px; border-bottom: 1px solid #DCE5EF; }
    .grid th { padding: 6px 8px; text-align: left; background: #0C5295; color: #fff; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
    .grid tr:nth-child(even) td { background: #F4F7FB; }
    .summary td { padding: 8px 10px; background: #D9ECFF; border: 3px solid #fff; }
    .big { font-size: 20px; font-weight: bold; color: #0C5295; }
    .num { text-align: right; }
    .cols td { vertical-align: top; width: 50%; }
    .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 8px; color: #6B7A90; }
</style>
</head>
<body>
    <div class="footer">Tourlast Sales Hub · generated {{ now()->format('j M Y, H:i') }} by {{ $generatedBy }} · statuses as reported by tourlast.com</div>

    <table class="header">
        <tr>
            <td style="width: 130px"><img src="data:image/png;base64,{{ $logo }}" style="width: 111px; height: 24px"></td>
            <td>
                <h1>Tourlast Partner Onboarding Report</h1>
                <div class="muted">Reporting period: {{ $filters->periodLabel() }} ({{ strtolower($filters->dateLabel()) }})</div>
            </td>
            <td class="muted" style="text-align: right">
                Status: {{ $statusLabel }}<br>
                Salesperson: {{ $salesperson?->name ?? 'All' }}<br>
                Type: {{ $filters->type ? config('hub.property_types.'.$filters->type) : 'All' }}
            </td>
        </tr>
    </table>

    <h2>Summary</h2>
    <table class="summary">
        <tr>
            <td style="width: 25%"><div class="muted">Total partners</div><div class="big">{{ $partners->count() }}</div></td>
            @foreach ($byType->take(3) as $label => $count)
                <td style="width: 25%"><div class="muted">{{ $label }}</div><div class="big">{{ $count }}</div></td>
            @endforeach
        </tr>
    </table>

    <table class="cols">
        <tr>
            <td style="padding-right: 10px">
                <h2>By property type</h2>
                <table class="grid">
                    <tr><th>Type</th><th class="num">Partners</th></tr>
                    @forelse ($byType as $label => $count)
                        <tr><td>{{ $label }}</td><td class="num">{{ $count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">None</td></tr>
                    @endforelse
                </table>
            </td>
            <td style="padding-left: 10px">
                <h2>Salesperson performance</h2>
                <table class="grid">
                    <tr><th>Salesperson</th><th class="num">Partners</th></tr>
                    @forelse ($bySalesperson as $name => $count)
                        <tr><td>{{ $name }}</td><td class="num">{{ $count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">None</td></tr>
                    @endforelse
                </table>
            </td>
        </tr>
    </table>

    <h2>Detailed list</h2>
    <table class="grid">
        <tr>
            <th>Property</th><th>Type</th><th>Location</th><th>Salesperson</th><th>Code</th><th>{{ $filters->dateLabel() }}</th><th>Status</th>
        </tr>
        @forelse ($partners as $partner)
            <tr>
                <td>{{ $partner->property_name }}</td>
                <td>{{ $partner->propertyTypeLabel() }}</td>
                <td>{{ $partner->location }}</td>
                <td>{{ $partner->user?->name ?? 'Unattributed' }}{{ $partner->attribution === 'manual' ? ' *' : '' }}</td>
                <td>{{ $partner->ref_code }}</td>
                <td>{{ $partner->{$filters->dateColumn()}?->format('j M Y') }}</td>
                <td>{{ $partner->status->label() }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No partners in this period.</td></tr>
        @endforelse
    </table>
    @if ($partners->contains('attribution', 'manual'))
        <p class="muted">* Credit assigned manually by an admin, with a recorded reason.</p>
    @endif
</body>
</html>
