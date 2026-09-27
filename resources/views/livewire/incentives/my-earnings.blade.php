@php
    $kes = fn ($amount) => 'KES '.number_format((float) $amount, fmod((float) $amount, 1) ? 2 : 0);
    $pts = fn ($value) => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
    $scaleMax = max(110, ceil(($earnings->points + $earnings->provisionalPoints) / 10) * 10);
    $pos = fn ($value) => min(100, round($value / $scaleMax * 100, 2));
    $gain = $withPending->total() - $earnings->total();
@endphp

<div class="grid gap-6">
    <x-ui.page-header
        :eyebrow="$isOwn ? 'My Earnings' : 'Earnings'"
        :title="$monthDate->format('F Y').($isCurrentMonth ? ' · expected earnings' : ' · earnings')"
        :description="($isOwn ? '' : $subject->name.' · ').'Paid by '.$paymentDue->format('j F').'. Points reset at the start of each month.'"
    >
        <x-slot:actions>
            <select wire:model.live="month" id="earnings-month" aria-label="Month" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] font-medium text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                @foreach ($monthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @if ($statement)
                <x-ui.pill :tone="$statement->statusTone()">Statement {{ $statement->status }}</x-ui.pill>
            @elseif ($isCurrentMonth)
                <x-ui.pill tone="brand">Month in progress · {{ max(0, (int) now()->diffInDays($monthDate->endOfMonth())) }} days left</x-ui.pill>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @unless ($hasAgreement)
        <div class="flex items-start gap-3 rounded-xl border border-warning/30 bg-warning-soft px-5 py-4 text-sm">
            <x-ui.icon name="alert" class="mt-0.5 size-5 text-warning" />
            <div class="grid gap-1">
                <p class="font-bold text-ink">No incentive agreement for {{ $monthDate->format('F Y') }}</p>
                <p class="text-ink-muted">Points are still recorded ({{ $pts($allPoints) }} this month), but incentives are paid only while an agreement is in force. HR or a Sales Admin can set one up under Admin → Incentives.</p>
            </div>
        </div>
    @endunless

    {{-- How you get paid --}}
    @if ($isOwn || auth()->user()->can('view-payment-details'))
        <x-ui.card>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="grid gap-1">
                    <p class="text-[13px] font-semibold text-ink-subtle">How {{ $isOwn ? 'you get' : $subject->firstName().' gets' }} paid</p>
                    @if ($paymentDetail?->isComplete())
                        <p class="text-sm text-ink">
                            <span class="font-bold">{{ \App\Models\PaymentDetail::Methods[$paymentDetail->method] }}</span>
                            · {{ $isOwn ? $paymentDetail->maskedDestination() : $paymentDetail->destination() }}
                            · <span class="font-semibold">{{ $paymentDetail->payeeName() }}</span>
                        </p>
                        <p class="text-xs text-ink-subtle">Updated {{ $paymentDetail->updated_at->diffForHumans() }}. Finance pays your statement here.</p>
                    @else
                        <p class="text-sm font-semibold text-warning">No payout details yet. Add them so Finance can pay you by the {{ $paymentDue->format('jS') }}.</p>
                    @endif
                </div>
                @if ($isOwn)
                    <div class="inline-flex rounded-md border border-line bg-surface p-0.5" role="group" aria-label="Payout method">
                        @foreach (\App\Models\PaymentDetail::Methods as $key => $label)
                            <button type="button" wire:click="openPayment('{{ $key }}')" wire:key="pay-{{ $key }}"
                                @class([
                                    'flex items-center gap-2 rounded px-2.5 py-1 text-xs font-medium transition-colors',
                                    'bg-brand text-brand-ink' => $paymentDetail?->method === $key,
                                    'text-ink-subtle hover:text-ink' => $paymentDetail?->method !== $key,
                                ])>
                                <span @class(['relative inline-flex h-4 w-7 rounded-full transition-colors', 'bg-white/40' => $paymentDetail?->method === $key, 'bg-line-strong' => $paymentDetail?->method !== $key])>
                                    <span @class(['absolute top-0.5 size-3 rounded-full bg-white shadow transition-all', 'left-3.5' => $paymentDetail?->method === $key, 'left-0.5' => $paymentDetail?->method !== $key])></span>
                                </span>
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-ui.card>
    @endif

    <div class="grid gap-4 lg:grid-cols-[1.25fr_1fr]">
        <x-ui.card>
            <p class="text-[13px] font-semibold text-ink-subtle">{{ $statement?->isLocked() ? 'Statement total' : 'Expected payment so far' }}</p>
            <p class="tabular mt-1 text-3xl leading-none font-bold tracking-tight text-ink">{{ $kes($statement?->isLocked() ? $statement->total : $earnings->total()) }}</p>
            <p class="mt-2 text-[13px] text-ink-muted">
                From <span class="font-bold text-ink">{{ $pts($earnings->points) }} approved points</span>.
                @if ($earnings->provisionalPoints > 0 && $gain > 0)
                    <span class="font-bold text-success">Up to {{ $kes($withPending->total()) }}</span> if the {{ $pts($earnings->provisionalPoints) }} provisional points are approved.
                @elseif ($earnings->provisionalPoints > 0)
                    {{ $pts($earnings->provisionalPoints) }} more points are waiting for approval.
                @endif
            </p>

            <dl class="mt-4 grid text-sm">
                @php
                    $lines = [
                        ['Performance Retainer', $earnings->retainerCompliant ? ($earnings->retainer ? ($earnings->points >= 30 ? '30+ points' : '18 to under 30 points') : 'needs 18 points') : 'reports, training or follow-up not confirmed', $earnings->retainer],
                        ['Monthly Performance Bonus', $earnings->points > 0 ? $pts($earnings->points).' points' : 'no points yet', $earnings->monthlyBonus],
                        ['Weekly Performance Bonus', count(array_filter($earnings->weeklyBonuses)).' of 4 weeks at 18+ points', $earnings->weeklyBonusTotal()],
                        ['Exceptional-Performance', $earnings->points > 76 ? $pts($earnings->points - 76).' points above 76' : 'starts above 76 points', $earnings->exceptional],
                        ['Airtime', 'approved claims · max KES '.number_format($policy->airtimeCap()), $earnings->airtime],
                        ['Transport', 'approved reimbursements', $earnings->transport],
                    ];
                @endphp
                @foreach ($lines as [$label, $hint, $amount])
                    <div class="flex items-baseline justify-between gap-4 border-b border-line py-2">
                        <dt class="grid leading-tight"><span class="text-ink">{{ $label }}</span><span class="text-xs text-ink-subtle">{{ $hint }}</span></dt>
                        <dd class="tabular font-bold text-ink">{{ number_format((float) $amount, fmod((float) $amount, 1) ? 2 : 0) }}</dd>
                    </div>
                @endforeach
                @foreach ($earnings->adjustmentLines as $line)
                    <div class="flex items-baseline justify-between gap-4 border-b border-line py-2">
                        <dt class="text-ink">{{ $line['label'] }}</dt>
                        <dd @class(['tabular font-bold', 'text-danger' => $line['amount'] < 0, 'text-success' => $line['amount'] > 0])>{{ number_format($line['amount'], 2) }}</dd>
                    </div>
                @endforeach
                <div class="flex items-baseline justify-between gap-4 pt-3">
                    <dt class="font-bold text-ink">Total</dt>
                    <dd class="tabular text-lg font-bold text-ink">{{ $kes($earnings->total()) }}</dd>
                </div>
            </dl>
            @if ($statement?->status === 'paid')
                <p class="mt-3 rounded-lg bg-success-soft px-3 py-2 text-[13px] text-success">Paid {{ $statement->paid_at->format('j M Y') }} · reference {{ $statement->payment_reference }}</p>
            @endif
        </x-ui.card>

        <div class="grid content-start gap-4">
            <x-ui.card title="Your next step up">
                @if ($nextStep && $isCurrentMonth)
                    <p class="text-sm text-ink"><span class="font-bold">{{ $pts($nextStep['needed']) }} more {{ $nextStep['needed'] == 1 ? 'point' : 'points' }}</span> takes you to {{ $pts($nextStep['points']) }} and unlocks <span class="font-bold">{{ $nextStep['label'] }}</span> (+{{ $kes($nextStep['gain']) }}).</p>
                    <p class="mt-2 text-[13px] text-ink-subtle">
                        For scale: a 51–100 room stay earns 5 points, an 11–50 room stay 3, a 16–30 service experience 3, a 6–15 service experience 2.
                    </p>
                @elseif (! $nextStep)
                    <p class="text-sm text-ink">You've reached the top of every band this month.</p>
                @else
                    <p class="text-sm text-ink-muted">This month is closed.</p>
                @endif
            </x-ui.card>

            <x-ui.card title="Waiting on others" :padding="false">
                @foreach ([
                    ['Awaiting verification by Sales Admin', $needsVerification, 'provisional points'],
                    ['In 14-day quality review', $inReview, 'must stay live and booking-ready'],
                    ['Expansion window open (90 days)', $expansionOpen, 'growth still earns points'],
                ] as [$label, $list, $hint])
                    <div class="flex items-center justify-between gap-4 border-b border-line px-4 py-2.5 last:border-b-0">
                        <div class="grid leading-tight"><span class="text-sm text-ink">{{ $label }}</span><span class="text-xs text-ink-subtle">{{ $hint }}</span></div>
                        <span class="tabular text-sm font-bold text-ink">{{ $list->count() }} {{ \Illuminate\Support\Str::plural('Account', $list->count()) }}</span>
                    </div>
                @endforeach
                <div class="px-4 py-2.5"><x-ui.button :href="route('accounts.index')" size="sm" variant="secondary" wire:navigate>Open my Accounts</x-ui.button></div>
            </x-ui.card>
        </div>
    </div>

    {{-- Points ladder --}}
    <x-ui.card title="Points ladder" description="Combined stays, experiences and expansion. Solid: approved. Striped: waiting for approval.">
        <div class="grid gap-1">
            <div class="relative h-9 text-[11px] text-ink-subtle">
                @foreach ($policy->rules['retainer'] as [$threshold, $amount])
                    <span class="absolute bottom-0 -translate-x-1/2 text-center leading-tight whitespace-nowrap" style="left: {{ $pos($threshold) }}%">Retainer {{ rtrim(rtrim(number_format($amount / 1000, 1), '0'), '.') }}k<b class="block text-xs text-ink">{{ $threshold }}</b></span>
                @endforeach
                <span class="absolute bottom-0 -translate-x-1/2 text-center leading-tight whitespace-nowrap" style="left: {{ $pos($policy->rules['exceptional']['above']) }}%">Exceptional<b class="block text-xs text-ink">{{ $policy->rules['exceptional']['above'] }}</b></span>
            </div>
            <div class="relative h-3 rounded-full bg-surface-muted">
                <div class="absolute inset-y-0 left-0 rounded-full bg-[repeating-linear-gradient(45deg,var(--tl-sky),var(--tl-sky)_4px,transparent_4px,transparent_8px)]" style="width: {{ $pos($earnings->points + $earnings->provisionalPoints) }}%"></div>
                <div class="absolute inset-y-0 left-0 rounded-full bg-brand" style="width: {{ $pos($earnings->points) }}%"></div>
                @foreach (array_merge($policy->retainerThresholds(), array_column($policy->rules['monthly_bonus']['bands'], 0)) as $mark)
                    <span class="absolute inset-y-0 w-px bg-surface" style="left: {{ $pos($mark) }}%"></span>
                @endforeach
            </div>
            <div class="relative h-9 text-[11px] text-ink-subtle">
                @foreach ($policy->rules['monthly_bonus']['bands'] as [$threshold, $amount])
                    <span class="absolute top-0 -translate-x-1/2 text-center leading-tight whitespace-nowrap" style="left: {{ $pos($threshold) }}%"><b class="block text-xs text-ink">{{ $threshold }}</b>{{ rtrim(rtrim(number_format($amount / 1000, 1), '0'), '.') }}k</span>
                @endforeach
            </div>
            <p class="text-xs text-ink-subtle">Above the bar: retainer thresholds. Below: Monthly Bonus bands (KES). Exceptional-Performance pays KES {{ $policy->rules['exceptional']['per_point'] }} per point above {{ $policy->rules['exceptional']['above'] }}, capped at KES {{ number_format($policy->rules['exceptional']['cap']) }}.</p>
        </div>
    </x-ui.card>

    <div class="grid gap-4 lg:grid-cols-[1.25fr_1fr]">
        <x-ui.card title="Bonus weeks" :description="'KES '.number_format($policy->rules['weekly_bonus']['amount']).' for each week with '.$policy->rules['weekly_bonus']['threshold'].'+ approved points'">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($weeks as $week)
                    @php
                        $approved = $approvedWeeks[$week['week']] ?? 0;
                        $pending = $provisionalWeeks[$week['week']] ?? 0;
                        $hit = ($earnings->weeklyBonuses[$week['week']] ?? 0) > 0;
                        $isNow = $isCurrentMonth && now()->day >= $week['from'] && now()->day <= $week['to'];
                    @endphp
                    <div @class(['grid gap-1 rounded-lg border p-3 text-xs', 'border-success bg-success-soft' => $hit, 'border-brand' => $isNow && ! $hit, 'border-line' => ! $hit && ! $isNow])>
                        <span class="text-ink-subtle">Week {{ $week['week'] }} · {{ $week['from'] }}–{{ $week['to'] }}</span>
                        <span class="tabular text-xl font-bold text-ink">{{ $pts($approved) }}</span>
                        @if ($hit)
                            <span class="font-bold text-success">KES {{ number_format($policy->rules['weekly_bonus']['amount']) }}</span>
                        @else
                            <span class="text-ink-subtle">{{ $pts(max(0, $policy->rules['weekly_bonus']['threshold'] - $approved)) }} to go{{ $pending ? ' · '.$pts($pending).' pending' : '' }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-ink-subtle">An Account's points land in the week of its Activation Date; expansion points in the week the expansion goes live. Points don't move between weeks.</p>
        </x-ui.card>

        <x-ui.card title="Points this month" :padding="false">
            @forelse ($entries as $entry)
                <a wire:key="pe-{{ $entry->id }}" href="{{ $entry->partnerAccount ? route('accounts.show', $entry->partnerAccount) : '#' }}" wire:navigate class="grid grid-cols-[1fr_auto_auto] items-center gap-3 border-b border-line px-4 py-2 last:border-b-0 hover:bg-surface-muted/60">
                    <span class="grid min-w-0 leading-tight">
                        <span @class(['truncate text-sm font-semibold', 'text-ink' => $entry->status !== 'cancelled', 'text-ink-subtle line-through' => $entry->status === 'cancelled'])>{{ $entry->partnerAccount?->legal_name ?? 'Adjustment' }}</span>
                        <span class="truncate text-xs text-ink-subtle">{{ $entry->typeLabel() }} · {{ $entry->earned_on->format('j M') }} · week {{ $entry->bonus_week }}</span>
                    </span>
                    <x-ui.pill :tone="$entry->statusTone()">{{ $entry->statusLabel() }}</x-ui.pill>
                    <span class="tabular w-10 text-right font-bold text-ink">{{ $entry->points > 0 ? '+' : '' }}{{ $pts($entry->points) }}</span>
                </a>
            @empty
                <x-ui.empty-state icon="chart" title="No points yet this month" description="Points appear when an Account you onboarded goes live on tourlast.com." />
            @endforelse
        </x-ui.card>
    </div>
    @if ($isOwn)
        <x-ui.modal wire:model="showPayment" :title="$paymentMethod === 'mpesa' ? 'Get paid by M-Pesa' : 'Get paid into a bank account'"
            :description="$paymentMethod === 'mpesa' ? 'Enter the number and the name that shows when money is sent to it.' : 'Enter the account exactly as the bank has it.'">
            <form id="payment-form" wire:submit="savePayment" class="grid gap-4">
                @if ($paymentMethod === 'mpesa')
                    <x-ui.input label="M-Pesa phone number" type="tel" wire:model="payment.mpesa_phone" id="pay-mpesa-phone" placeholder="0712 345 678" autocomplete="tel" />
                    <x-ui.input label="Registered M-Pesa name" wire:model="payment.mpesa_name" id="pay-mpesa-name" hint="The name M-Pesa shows when Finance sends the money, e.g. JOHN DOE." />
                @else
                    <x-ui.input label="Bank" wire:model="payment.bank_name" id="pay-bank" placeholder="e.g. Equity Bank" />
                    <x-ui.input label="Branch" wire:model="payment.bank_branch" id="pay-branch" placeholder="Optional" />
                    <x-ui.input label="Account number" wire:model="payment.account_number" id="pay-account" inputmode="numeric" />
                    <x-ui.input label="Account name" wire:model="payment.account_name" id="pay-account-name" hint="As it appears on your bank statement." />
                @endif
                <p class="flex items-start gap-2 rounded-lg bg-surface-muted px-3 py-2 text-xs text-ink-muted">
                    <x-ui.icon name="lock" class="mt-0.5 size-4 text-brand-text" />
                    Stored encrypted. Only Finance, HR and admins can see it. Sales Managers can't. Finance and HR are notified whenever you change it.
                </p>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="payment-form">Save {{ $paymentMethod === 'mpesa' ? 'M-Pesa' : 'bank' }} details</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>