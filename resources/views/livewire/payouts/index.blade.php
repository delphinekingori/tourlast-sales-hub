@php
    $pts = fn ($value) => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
@endphp

<div class="grid gap-6">
    <x-ui.page-header eyebrow="Finance" title="Payouts" :description="'Incentive statements for '.$monthDate->format('F Y').'. Payment due by '.$dueOn->format('j F Y').'.'">
        <x-slot:actions>
            <select wire:model.live="month" id="payout-month" aria-label="Month" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] font-medium text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                @foreach ($monthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @if ($canManage || $canConfirm)
                <x-ui.button variant="secondary" icon="refresh" wire:click="generate">Refresh drafts</x-ui.button>
            @endif
            <x-ui.button icon="register" :href="route('downloads.payouts', ['month' => $monthDate->format('Y-m')])">Export Excel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat label="Statements" :value="$totals['count']" hint="Salespeople with an agreement this month" />
        <x-ui.stat label="Total payable" :value="'KES '.number_format($totals['total'])" />
        <x-ui.stat label="Paid" :value="'KES '.number_format($totals['paid'])" :hint="$totals['total'] ? round($totals['paid'] / max(1, $totals['total']) * 100).'% of total' : null" />
    </div>

    <x-ui.table-card>
        <table class="w-full min-w-[980px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Salesperson</th>
                    <th class="text-right">Points</th>
                    <th class="text-right">Retainer</th>
                    <th class="text-right">Weekly</th>
                    <th class="text-right">Monthly</th>
                    <th class="text-right">Exceptional</th>
                    <th class="text-right">Claims</th>
                    <th class="text-right">Adjust.</th>
                    <th class="text-right">Total (KES)</th>
                    <th class="text-left">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($statements as $statement)
                    <tr wire:key="st-{{ $statement->id }}" wire:click="view({{ $statement->id }})" class="cursor-pointer hover:bg-surface-muted/60">
                        <td><div class="flex items-center gap-3"><x-ui.avatar :user="$statement->user" size="sm" /><span class="font-semibold whitespace-nowrap text-ink">{{ $statement->user->name }}</span></div></td>
                        <td class="tabular text-right text-ink">{{ $pts($statement->points) }}</td>
                        <td class="tabular text-right text-ink-muted">{{ number_format($statement->retainer) }}@unless ($statement->isCompliant())<span class="block text-[11px] text-warning">conditions?</span>@endunless</td>
                        <td class="tabular text-right text-ink-muted">{{ number_format($statement->weekly_bonus) }}</td>
                        <td class="tabular text-right text-ink-muted">{{ number_format($statement->monthly_bonus) }}</td>
                        <td class="tabular text-right text-ink-muted">{{ number_format($statement->exceptional) }}</td>
                        <td class="tabular text-right text-ink-muted">{{ number_format($statement->airtime + $statement->transport) }}</td>
                        <td @class(['tabular text-right', 'text-danger' => $statement->adjustments < 0, 'text-success' => $statement->adjustments > 0, 'text-ink-muted' => $statement->adjustments == 0])>{{ number_format($statement->adjustments) }}</td>
                        <td class="tabular text-right font-bold text-ink">{{ number_format($statement->total) }}</td>
                        <td><x-ui.pill :tone="$statement->statusTone()">{{ ucfirst($statement->status) }}</x-ui.pill></td>
                    </tr>
                @empty
                    <tr><td colspan="10"><x-ui.empty-state icon="register" title="No statements for this month" description="Press Refresh drafts to create one for every salesperson with an incentive agreement. Drafts are also created automatically on the 1st." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    <x-ui.slide-over wire:model="showDetail" :title="$viewing ? $viewing->user->name.' · '.$viewing->month->format('F Y') : 'Statement'" :description="$viewing ? 'Status: '.ucfirst($viewing->status).($viewing->paid_at ? ' · paid '.$viewing->paid_at->format('j M').' · '.$viewing->payment_reference : '') : null">
        @if ($viewing)
            <div class="grid gap-6">
                <dl class="grid text-sm">
                    @foreach ([
                        ['Points (approved)', $pts($viewing->points)],
                        ['Weekly points', collect($viewing->weekly_points ?? [])->map(fn ($p, $w) => 'W'.$w.': '.$pts($p))->implode(' · ')],
                        ['Performance Retainer', number_format($viewing->retainer)],
                        ['Weekly Performance Bonus', number_format($viewing->weekly_bonus)],
                        ['Monthly Performance Bonus', number_format($viewing->monthly_bonus)],
                        ['Exceptional-Performance', number_format($viewing->exceptional)],
                        ['Airtime', number_format($viewing->airtime, 2)],
                        ['Transport reimbursements', number_format($viewing->transport, 2)],
                    ] as [$label, $value])
                        <div class="flex justify-between gap-4 border-b border-line py-2"><dt class="text-ink-muted">{{ $label }}</dt><dd class="tabular text-right font-semibold text-ink">{{ $value }}</dd></div>
                    @endforeach
                    @foreach ($viewing->adjustment_lines ?? [] as $line)
                        <div class="flex justify-between gap-4 border-b border-line py-2"><dt class="text-ink-muted">{{ $line['label'] }}</dt><dd @class(['tabular font-semibold', 'text-danger' => $line['amount'] < 0, 'text-success' => $line['amount'] > 0])>{{ number_format($line['amount'], 2) }}</dd></div>
                    @endforeach
                    <div class="flex justify-between gap-4 pt-3"><dt class="font-bold text-ink">Total</dt><dd class="tabular text-lg font-bold text-ink">KES {{ number_format($viewing->total, 2) }}</dd></div>
                </dl>

                @can('view-payment-details')
                    @php
                        $payTo = $viewing->user->paymentDetail;
                    @endphp
                    <div @class(['grid gap-1 rounded-xl p-4 text-sm', 'bg-brand-soft/60' => $payTo, 'bg-warning-soft' => ! $payTo])>
                        <p class="text-[13px] font-bold text-ink">Pay to</p>
                        @if ($payTo)
                            <p class="text-ink">{{ \App\Models\PaymentDetail::Methods[$payTo->method] }} · <span class="font-mono select-all">{{ $payTo->destination() }}</span></p>
                            <p class="font-semibold text-ink">{{ $payTo->payeeName() }}</p>
                        @else
                            <p class="text-warning">No payout details yet. Ask {{ $viewing->user->firstName() }} to add them on My Earnings.</p>
                        @endif
                    </div>
                @endcan

                <div class="grid gap-3 rounded-xl border border-line p-4">
                    <p class="text-[13px] font-bold text-ink">Retainer conditions (paragraph 5)</p>
                    @foreach (\App\Models\PayoutStatement::ComplianceItems as $key => $label)
                        <label class="flex items-center gap-2.5 text-sm text-ink">
                            <input type="checkbox" wire:model="compliance.{{ $key }}" id="compliance-{{ $key }}" class="size-4 accent-[var(--tl-brand)]" @disabled(! $canConfirm || $viewing->isLocked())>
                            {{ $label }}
                        </label>
                    @endforeach
                    <p class="text-xs text-ink-subtle">The retainer is paid only when all four are confirmed by a Sales Admin.</p>
                    @if ($canConfirm && ! $viewing->isLocked())
                        <div><x-ui.button size="sm" variant="secondary" wire:click="saveCompliance">Save conditions</x-ui.button></div>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-ui.button variant="secondary" icon="register" :href="route('downloads.statement', $viewing)">PDF statement &amp; report</x-ui.button>
                    <x-ui.button variant="ghost" :href="route('earnings.member', $viewing->user)" wire:navigate>Open earnings</x-ui.button>
                </div>

                @if ($canManage && $viewing->status === 'draft')
                    <div class="grid gap-2 rounded-xl border border-line bg-surface-muted/50 p-4">
                        <p class="text-[13px] text-ink-muted">Approving freezes these figures. Later changes are carried to the next statement as adjustments.</p>
                        <div><x-ui.button icon="check" wire:click="approve">Approve statement</x-ui.button></div>
                    </div>
                @elseif ($canManage && $viewing->status === 'approved')
                    <form wire:submit="markPaid" class="grid gap-3 rounded-xl border border-line bg-surface-muted/50 p-4">
                        <x-ui.input label="Payment reference" wire:model="paymentReference" id="statement-reference" placeholder="e.g. Bank transfer ref or M-Pesa code" />
                        <div><x-ui.button type="submit">Mark as paid</x-ui.button></div>
                    </form>
                @endif
            </div>
        @endif
    </x-ui.slide-over>
</div>
