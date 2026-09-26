<div class="grid gap-5">
    <x-ui.page-header
        eyebrow="Approvals"
        title="Claim approvals"
        :description="'You approve as: '.collect($steps)->map(fn ($s) => \App\Models\ExpenseClaim::StepLabels[$s])->implode(', ').'. Transport goes Sales Manager → HR → Finance; airtime goes straight to Finance.'"
    />

    <div class="inline-flex flex-wrap justify-self-start rounded-md border border-line bg-surface p-0.5">
        @foreach (array_filter(['mine' => 'Waiting for me', 'disburse' => $isFinance ? 'To disburse' : null, 'all' => 'All claims']) as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" wire:key="appr-tab-{{ $key }}"
                @class(['flex items-center gap-2 rounded px-2.5 py-1 text-xs font-medium', 'bg-brand-soft text-brand-text' => $tab === $key, 'text-ink-subtle hover:text-ink' => $tab !== $key])>
                {{ $label }}
                @if ($key === 'mine' && $waitingCount)<span class="rounded-full bg-warning-soft px-1.5 text-[11px] font-bold text-warning">{{ $waitingCount }}</span>@endif
                @if ($key === 'disburse' && $disburseCount)<span class="rounded-full bg-warning-soft px-1.5 text-[11px] font-bold text-warning">{{ $disburseCount }}</span>@endif
            </button>
        @endforeach
    </div>

    <x-ui.table-card :paginator="$claims">
        <table class="w-full min-w-[820px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Salesperson</th>
                    <th class="text-left">Claim</th>
                    <th class="text-left">Trip</th>
                    <th class="text-right">Amount (KES)</th>
                    <th class="text-left">Files</th>
                    <th class="text-left">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($claims as $claim)
                    <tr wire:key="appr-{{ $claim->id }}" wire:click="view({{ $claim->id }})" class="cursor-pointer hover:bg-surface-muted/60">
                        <td>
                            <div class="flex items-center gap-3"><x-ui.avatar :user="$claim->user" size="sm" /><span class="font-semibold whitespace-nowrap text-ink">{{ $claim->user->name }}</span></div>
                        </td>
                        <td><div class="grid leading-tight"><span class="font-semibold text-ink">{{ $claim->typeLabel() }}</span><span class="text-[13px] text-ink-subtle">{{ $claim->created_at->format('j M Y') }}</span></div></td>
                        <td class="text-ink-muted">
                            @if ($claim->isTransport())
                                <div class="grid leading-tight"><span>{{ $claim->pickup }} → {{ $claim->dropoff }}</span><span class="text-[13px] text-ink-subtle">{{ $claim->rideProviderLabel() }} · {{ $claim->travel_date?->format('j M') }}</span></div>
                            @else — @endif
                        </td>
                        <td class="tabular text-right font-bold text-ink">{{ number_format($claim->payableAmount(), 2) }}</td>
                        <td class="text-ink-muted">{{ $claim->attachments->count() }}</td>
                        <td><x-ui.pill :tone="$claim->statusTone()">{{ $claim->statusLabel() }}</x-ui.pill></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-ui.empty-state icon="check-circle" title="Nothing waiting for you" description="Claims appear here when they reach your step." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    <x-ui.slide-over wire:model="showDetail" :title="$viewing ? $viewing->typeLabel().' · '.$viewing->user->name : 'Claim'" :description="$viewing ? 'Submitted '.$viewing->created_at->format('j M Y, H:i') : null">
        @if ($viewing)
            @include('livewire.claims.partials.detail', ['claim' => $viewing])

            @if ($canDecide)
                <div class="mt-6 grid gap-4 rounded-xl border border-line bg-surface-muted/50 p-4">
                    <p class="text-[13px] font-bold text-ink">Your decision as {{ \App\Models\ExpenseClaim::StepLabels[$viewing->current_step] }}</p>
                    @if ($viewing->current_step === 'finance')
                        <x-ui.input label="Approved amount (KES)" type="number" step="0.01" wire:model="decisionAmount" id="decision-amount"
                            :hint="$airtimeRemaining !== null ? 'KES '.number_format($airtimeRemaining).' of this month\'s airtime allowance is left.' : 'Lower it if part of the claim isn\'t covered.'" />
                    @endif
                    <div class="grid gap-1.5">
                        <label for="decision-note" class="text-xs font-medium text-ink-muted">Note <span class="font-normal text-ink-subtle">(required to reject)</span></label>
                        <textarea id="decision-note" wire:model="decisionNote" rows="2" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none"></textarea>
                        @error('decisionNote')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex gap-2">
                        <x-ui.button icon="check" wire:click="approve">Approve</x-ui.button>
                        <x-ui.button variant="danger-ghost" wire:click="reject">Reject</x-ui.button>
                    </div>
                </div>
            @elseif ($isFinance && $viewing->isRequest() && $viewing->status === 'approved')
                <form wire:submit="disburse" class="mt-6 grid gap-4 rounded-xl border border-line bg-surface-muted/50 p-4">
                    <p class="text-[13px] font-bold text-ink">Release the money</p>
                    <x-ui.input label="Payment reference" wire:model="paymentReference" id="payment-reference" placeholder="e.g. M-Pesa QJK7H2L9XP" />
                    <div><x-ui.button type="submit">Mark as disbursed</x-ui.button></div>
                </form>
            @endif
        @endif
    </x-ui.slide-over>
</div>
