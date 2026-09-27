<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Tourlast Property Engagement Report</title>
<style>
    @page { margin: 28px 32px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #0F1B2D; }
    h1 { font-size: 18px; margin: 0; color: #0C5295; }
    h2 { font-size: 11px; margin: 16px 0 7px; color: #0C5295; text-transform: uppercase; letter-spacing: .06em; }
    .muted { color: #6B7A90; }
    .header { border-bottom: 3px solid #0C5295; padding-bottom: 10px; margin-bottom: 12px; }
    .header td { vertical-align: middle; }
    table { width: 100%; border-collapse: collapse; }
    .grid td { padding: 5px 7px; border-bottom: 1px solid #DCE5EF; }
    .grid th { padding: 5px 7px; text-align: left; background: #0C5295; color: #fff; font-size: 8.5px; text-transform: uppercase; letter-spacing: .04em; }
    .grid tr:nth-child(even) td { background: #F4F7FB; }
    .summary td { padding: 7px 9px; background: #D9ECFF; border: 3px solid #fff; width: 11.1%; }
    .big { font-size: 17px; font-weight: bold; color: #0C5295; }
    .num { text-align: right; }
    .cols td { vertical-align: top; }
    .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 8px; color: #6B7A90; }
</style>
</head>
<body>
    <div class="footer">Tourlast Sales Hub · Property Engagement Registry · generated {{ now()->format('j M Y, H:i') }} by {{ $generatedBy }}</div>

    <table class="header">
        <tr>
            <td style="width: 130px"><img src="data:image/png;base64,{{ $logo }}" style="width: 111px; height: 24px"></td>
            <td>
                <h1>Tourlast Property Engagement Report</h1>
                <div class="muted">Reporting period: {{ $from->format('d F Y') }} – {{ $to->format('d F Y') }}</div>
            </td>
            <td class="muted" style="text-align: right">Properties first engaged or engaged again in the period</td>
        </tr>
    </table>

    <h2>Summary</h2>
    <table class="summary">
        <tr>
            @foreach ($summary as $label => $value)
                <td><div class="muted">{{ $label }}</div><div class="big">{{ $value }}</div></td>
            @endforeach
        </tr>
    </table>

    <table class="cols">
        <tr>
            <td style="width: 62%; padding-right: 10px">
                <h2>Engagement by salesperson</h2>
                <table class="grid">
                    <tr><th>Salesperson</th><th class="num">Properties engaged</th><th class="num">Onboarding</th><th class="num">Active</th><th class="num">Lost</th><th class="num">Onboarded</th></tr>
                    @forelse ($bySalesperson as $name => $row)
                        <tr><td>{{ $name }}</td><td class="num">{{ $row['engaged'] }}</td><td class="num">{{ $row['onboarding'] }}</td><td class="num">{{ $row['active'] }}</td><td class="num">{{ $row['lost'] }}</td><td class="num">{{ $row['live'] }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="muted">None</td></tr>
                    @endforelse
                </table>
            </td>
            <td style="width: 38%; padding-left: 10px">
                <h2>Engagement by property type</h2>
                <table class="grid">
                    <tr><th>Type</th><th class="num">Properties</th></tr>
                    @forelse ($byType as $label => $count)
                        <tr><td>{{ $label }}</td><td class="num">{{ $count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">None</td></tr>
                    @endforelse
                </table>
            </td>
        </tr>
    </table>

    <h2>Detailed property list</h2>
    <table class="grid">
        <tr>
            <th>Property</th><th>Type</th><th>Location</th><th>Contact</th><th>Salesperson</th><th>Stage</th><th>Status</th><th>First engaged</th><th>Last engaged</th><th>Source</th>
        </tr>
        @forelse ($engagements as $engagement)
            <tr>
                <td>{{ $engagement->name }}</td>
                <td>{{ $engagement->propertyTypeLabel() }}</td>
                <td>{{ $engagement->locationLabel() }}</td>
                <td>{{ $engagement->primaryContact?->name }}{{ $engagement->primaryContact?->phone ? ' · '.$engagement->primaryContact->phone : '' }}</td>
                <td>{{ $engagement->salesRep?->name ?? 'Unassigned' }}</td>
                <td>{{ $engagement->stage->label() }}</td>
                <td>{{ $engagement->status->label() }}</td>
                <td>{{ $engagement->first_engaged_on->format('j M Y') }}</td>
                <td>{{ $engagement->last_engaged_on?->format('j M Y') }}</td>
                <td>{{ $engagement->source?->label() }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="muted">No properties engaged in this period.</td></tr>
        @endforelse
    </table>
</body>
</html>
