<div class="grid gap-5">
    <x-ui.page-header eyebrow="Finance" title="Payment details" description="Where each salesperson's incentives are paid. Salespeople enter these on My Earnings. Visible to admins, HR and Finance only." />

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="method" id="pd-method" aria-label="Method" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                <option value="">All methods</option>
                <option value="mpesa">M-Pesa</option>
                <option value="bank">Bank account</option>
                <option value="missing">Not provided</option>
            </select>
            @if ($missing)
                <x-ui.pill tone="warning">{{ $missing }} without payout details</x-ui.pill>
            @endif
        </div>
        <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search name or email" />
    </div>

    <x-ui.table-card>
        <table class="w-full min-w-[900px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Salesperson</th>
                    <th class="text-left">Method</th>
                    <th class="text-left">Number</th>
                    <th class="text-left">Name on account</th>
                    <th class="text-left">Bank / branch</th>
                    <th class="text-left">Updated</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($people as $person)
                    @php
                        $detail = $person->paymentDetail;
                    @endphp
                    <tr wire:key="pd-{{ $person->id }}" @class(['opacity-60' => ! $person->is_active])>
                        <td>
                            <div class="flex items-center gap-3">
                                <x-ui.avatar :user="$person" size="sm" />
                                <span class="grid leading-tight"><span class="font-semibold text-ink">{{ $person->name }}</span><span class="text-[13px] text-ink-subtle">{{ $person->job_title ?? $person->role()?->label() }}</span></span>
                            </div>
                        </td>
                        @if ($detail)
                            <td><x-ui.pill :tone="$detail->method === 'mpesa' ? 'success' : 'brand'" :dot="false">{{ \App\Models\PaymentDetail::Methods[$detail->method] }}</x-ui.pill></td>
                            <td class="font-mono text-[13px] text-ink select-all">{{ $detail->method === 'mpesa' ? $detail->mpesa_phone : $detail->account_number }}</td>
                            <td class="font-semibold text-ink">{{ $detail->payeeName() }}</td>
                            <td class="text-ink-muted">{{ $detail->method === 'bank' ? trim($detail->bank_name.($detail->bank_branch ? ' · '.$detail->bank_branch : '')) : '—' }}</td>
                            <td class="text-ink-muted">{{ $detail->updated_at->format('j M Y') }}</td>
                        @else
                            <td colspan="5"><x-ui.pill tone="warning">Not provided</x-ui.pill></td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="6"><x-ui.empty-state icon="lock" title="Nobody matches" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>
</div>
