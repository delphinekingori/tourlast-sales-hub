<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Incentive statement</title>
<style>
    @page { margin: 28px 32px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #0F1B2D; }
    h1 { font-size: 17px; margin: 0; color: #0C5295; }
    h2 { font-size: 11px; margin: 16px 0 6px; color: #0C5295; text-transform: uppercase; letter-spacing: .06em; }
    .muted { color: #6B7A90; }
    .header { border-bottom: 3px solid #0C5295; padding-bottom: 8px; margin-bottom: 12px; width: 100%; }
    table { width: 100%; border-collapse: collapse; }
    .grid td { padding: 5px 7px; border-bottom: 1px solid #DCE5EF; vertical-align: top; }
    .grid th { padding: 5px 7px; text-align: left; background: #0C5295; color: #fff; font-size: 8.5px; text-transform: uppercase; }
    .num { text-align: right; }
    .total td { font-weight: bold; font-size: 11px; border-top: 2px solid #0C5295; }
    .box { background: #D9ECFF; padding: 8px 10px; }
    .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 7.5px; color: #6B7A90; }
    .acct { page-break-inside: avoid; margin-bottom: 10px; border: 1px solid #DCE5EF; }
    .acct td { padding: 4px 7px; vertical-align: top; }
    .acct .label { color: #6B7A90; width: 32%; }
</style>
</head>
<body>
@php
    $pts = fn ($value) => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
@endphp
<div class="footer">Tourlast Sales Hub · Schedule 1 incentive statement · generated {{ now()->format('j M Y, H:i') }} · status {{ $statement->status }}{{ $statement->payment_reference ? ' · paid ref '.$statement->payment_reference : '' }}</div>

<table class="header">
    <tr>
        <td style="width: 130px"><img src="data:image/png;base64,{{ $logo }}" style="width: 111px; height: 24px"></td>
        <td>
            <h1>Incentive statement · {{ $statement->month->format('F Y') }}</h1>
            <div class="muted">{{ $statement->user->name }} · {{ $statement->user->email }} · payment due {{ $dueOn->format('j M Y') }}</div>
        </td>
    </tr>
</table>

<h2>Summary</h2>
<table class="grid">
    <tr><th>Component</th><th>Basis</th><th class="num">KES</th></tr>
    <tr><td>Performance Retainer</td><td>{{ $pts($statement->points) }} approved points{{ $statement->isCompliant() ? '' : ' · retainer conditions not confirmed' }}</td><td class="num">{{ number_format($statement->retainer) }}</td></tr>
    <tr><td>Weekly Performance Bonus</td><td>{{ collect($statement->weekly_points ?? [])->map(fn ($p, $w) => 'W'.$w.' '.$pts($p))->implode(' · ') }}</td><td class="num">{{ number_format($statement->weekly_bonus) }}</td></tr>
    <tr><td>Monthly Performance Bonus</td><td>{{ $pts($statement->points) }} combined points</td><td class="num">{{ number_format($statement->monthly_bonus) }}</td></tr>
    <tr><td>Exceptional-Performance</td><td>Points above 76</td><td class="num">{{ number_format($statement->exceptional) }}</td></tr>
    <tr><td>Airtime allowance</td><td>Approved claims</td><td class="num">{{ number_format($statement->airtime, 2) }}</td></tr>
    <tr><td>Transport reimbursement</td><td>Approved claims</td><td class="num">{{ number_format($statement->transport, 2) }}</td></tr>
    @foreach ($statement->adjustment_lines ?? [] as $line)
        <tr><td>Adjustment</td><td>{{ $line['label'] }}</td><td class="num">{{ number_format($line['amount'], 2) }}</td></tr>
    @endforeach
    <tr class="total"><td colspan="2">Total</td><td class="num">{{ number_format($statement->total, 2) }}</td></tr>
</table>

<h2>Claimed Accounts (Schedule 1, paragraph 13)</h2>
@forelse ($accounts as $account)
    @php
        $entries = $account->pointEntries->filter(fn ($e) => $e->month->isSameMonth($statement->month));
        $done = $account->checklistItems->whereNotNull('completed_at')->count();
        $items = $account->checklistItems->keyBy(fn ($row) => $row->item->value);
    @endphp
    <table class="acct">
        <tr><td class="label">Partner / legal entity</td><td><b>{{ $account->legal_name }}</b> ({{ $account->onboardings->map(fn ($onboarding) => $onboarding->property_name.($onboarding->trashed() ? ' (deleted)' : ''))->implode(', ') }})</td></tr>
        <tr><td class="label">Provider category</td><td>{{ $account->categoryLabel() }} · {{ $account->activation_inventory ?? '—' }} {{ strtolower($account->basisLabel()) }}{{ $account->inventory_note ? ' · '.$account->inventory_note : '' }}</td></tr>
        <tr><td class="label">Referral attribution</td><td>{{ $account->onboardings->pluck('ref_code')->filter()->unique()->implode(', ') ?: 'Assigned by admin' }}</td></tr>
        <tr><td class="label">Activation Date</td><td>{{ $account->activation_date?->format('j M Y') ?? '—' }}</td></tr>
        <tr><td class="label">Claimed points</td><td>
            @foreach ($entries as $entry)
                {{ $entry->typeLabel() }}: {{ $pts($entry->points) }} ({{ $entry->statusLabel() }}{{ $entry->reason ? ' · '.$entry->reason : '' }})<br>
            @endforeach
        </td></tr>
        <tr><td class="label">Agreement status</td><td>{{ $items->get('agreement_signed')?->completed_at ? 'Signed' : 'Not confirmed' }}</td></tr>
        <tr><td class="label">Training status</td><td>{{ $items->get('partner_trained')?->completed_at ? 'Trained, able to log in' : 'Not confirmed' }}</td></tr>
        <tr><td class="label">Account status</td><td>{{ $account->isVerified() ? 'Verified' : 'Awaiting verification' }} · review {{ str_replace('_', ' ', $account->reviewStatus()) }} · checklist {{ $done }}/{{ count($checklist) }}</td></tr>
        <tr><td class="label">Follow-up records</td><td>{{ $followUps[$account->id] ?? 0 }} logged activities</td></tr>
        <tr><td class="label">Supporting evidence</td><td>{{ $account->checklistItems->whereNotNull('evidence_path')->pluck('evidence_name')->implode(', ') ?: 'None uploaded' }}</td></tr>
    </table>
@empty
    <p class="muted">No Accounts earned points this month.</p>
@endforelse
</body>
</html>
