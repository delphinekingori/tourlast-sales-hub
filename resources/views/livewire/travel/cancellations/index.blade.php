@php
    $isCancellations = $tab === 'cancellations';
    $mayDecide = fn (int $requestedBy) => $canDecide && ($requestedBy !== $userId || $isSuperAdmin);
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="Cancellations & refunds" description="Cancellation and refund requests on package bookings. A Sales Admin approves them; Accounts pays approved refunds out and records the M-Pesa or bank reference." />

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-4">
        @foreach ([
            ['Cancellations awaiting decision', number_format($summary['pendingCancellations']), $summary['pendingCancellations'] ? 'text-warning' : 'text-ink'],
            ['Refund requests awaiting decision', number_format($summary['requestedRefunds']), $summary['requestedRefunds'] ? 'text-warning' : 'text-ink'],
            ['Approved, to pay out', 'KES '.number_format((float) $summary['toPayOut']), 'text-brand-text'],
            ['Refunds paid', 'KES '.number_format((float) $summary['paidOut']), 'text-ink'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular truncate text-xl leading-tight font-bold {{ $tone }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="flex flex-wrap items-center gap-3">
        <x-ui.segmented wire:model.live="tab" :options="['cancellations' => 'Cancellations', 'refunds' => 'Refunds']" />
        <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Booking ID, client or package" />
        <div class="w-48">
            <x-ui.select wire:model.live="status" id="cr-status" aria-label="Status">
                <option value="">Any status</option>
                @foreach ($isCancellations ? \App\Enums\Travel\CancellationStatus::cases() : \App\Enums\Travel\RefundStatus::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>
        </div>
    </div>

    <x-ui.table-card :paginator="$rows">
        @if ($isCancellations)
            <table class="w-full min-w-[1240px] text-sm">
                <thead class="text-left text-ink-subtle uppercase">
                    <tr>
                        <th>Booking</th>
                        <th>Client</th>
                        <th>Package</th>
                        <th>Travel date</th>
                        <th>Requested</th>
                        <th>Reason</th>
                        <th>Policy</th>
                        <th class="text-right">Refund</th>
                        <th>Refund status</th>
                        <th>Status</th>
                        <th>Processed by</th>
                        <th class="text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($rows as $row)
                        <tr wire:key="can-{{ $row->id }}">
                            <td class="whitespace-nowrap"><a href="{{ route('travel.bookings.show', $row->booking) }}" wire:navigate class="font-medium text-ink hover:text-brand-text">{{ $row->booking->reference }}</a></td>
                            <td class="text-ink">{{ $row->booking->client->name }}</td>
                            <td class="text-ink-muted">{{ $row->booking->package->name }}</td>
                            <td class="whitespace-nowrap text-ink-muted">{{ $row->booking->departure->dateLabel() }}</td>
                            <td class="whitespace-nowrap text-ink-muted">{{ $row->created_at->format('j M Y') }}<span class="block text-xs text-ink-subtle">{{ $row->requester?->name }}</span></td>
                            <td class="max-w-56 text-ink-muted"><span class="line-clamp-2">{{ $row->reason }}</span></td>
                            <td class="max-w-48 text-xs text-ink-subtle"><span class="line-clamp-2" title="{{ $row->policy_snapshot }}">{{ $row->policy_snapshot ?? '—' }}</span></td>
                            <td class="tabular text-right whitespace-nowrap">KES {{ number_format((float) $row->refund_amount) }}</td>
                            <td>
                                @php $refund = $row->refunds->first(); @endphp
                                @if ($refund)<x-ui.pill :tone="$refund->status->tone()" :dot="false">{{ $refund->status->label() }}</x-ui.pill>@else<span class="text-ink-subtle">—</span>@endif
                            </td>
                            <td><x-ui.pill :tone="$row->status->tone()">{{ $row->status->label() }}</x-ui.pill></td>
                            <td class="text-ink-muted">{{ $row->processor?->name ?? $row->decider?->name ?? '—' }}</td>
                            <td class="text-right whitespace-nowrap">
                                @if ($row->status === \App\Enums\Travel\CancellationStatus::Pending && $mayDecide($row->requested_by))
                                    <x-ui.button size="sm" wire:click="openDecision('cancellation', {{ $row->id }})">Review</x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="12"><x-ui.empty-state icon="refresh" title="No cancellations" description="Cancellation requests on package bookings appear here." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="w-full min-w-[1240px] text-sm">
                <thead class="text-left text-ink-subtle uppercase">
                    <tr>
                        <th>Booking</th>
                        <th>Client</th>
                        <th>Package</th>
                        <th class="text-right">Original amount</th>
                        <th class="text-right">Refund</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Processed by</th>
                        <th>Reference</th>
                        <th class="text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($rows as $row)
                        <tr wire:key="ref-{{ $row->id }}">
                            <td class="whitespace-nowrap"><a href="{{ route('travel.bookings.show', $row->booking) }}" wire:navigate class="font-medium text-ink hover:text-brand-text">{{ $row->booking->reference }}</a></td>
                            <td class="text-ink">{{ $row->booking->client->name }}</td>
                            <td class="text-ink-muted">{{ $row->booking->package->name }}</td>
                            <td class="tabular text-right whitespace-nowrap">KES {{ number_format((float) $row->booking->amount_total) }}</td>
                            <td class="tabular text-right font-medium whitespace-nowrap">KES {{ number_format((float) $row->amount) }}</td>
                            <td class="max-w-56 text-ink-muted"><span class="line-clamp-2">{{ $row->reason }}</span></td>
                            <td><x-ui.pill :tone="$row->status->tone()">{{ $row->status->label() }}</x-ui.pill>
                                @if ($row->failure_reason)<span class="mt-0.5 block text-xs text-ink-subtle">{{ $row->failure_reason }}</span>@endif
                            </td>
                            <td class="whitespace-nowrap text-ink-muted">{{ ($row->processed_at ?? $row->approved_at ?? $row->created_at)->format('j M Y') }}</td>
                            <td class="text-ink-muted">{{ $row->processor?->name ?? $row->approver?->name ?? '—' }}</td>
                            <td class="text-ink-muted">{{ $row->reference ? $row->method?->label().' '.$row->reference : '—' }}</td>
                            <td class="text-right whitespace-nowrap">
                                @if ($row->status === \App\Enums\Travel\RefundStatus::Requested && $mayDecide($row->requested_by))
                                    <x-ui.button size="sm" wire:click="openDecision('refund', {{ $row->id }})">Review</x-ui.button>
                                @endif
                                @if ($canPayOut && in_array($row->status, [\App\Enums\Travel\RefundStatus::Approved, \App\Enums\Travel\RefundStatus::Processing], true))
                                    <x-ui.button size="sm" variant="secondary" wire:click="openPayout({{ $row->id }})">Pay out</x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11"><x-ui.empty-state icon="wallet" title="No refunds" description="Refunds on package bookings appear here." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    </x-ui.table-card>

    <x-ui.modal wire:model="showDecision" :title="$decisionType === 'refund' ? 'Review refund' : 'Review cancellation'" description="Approving a cancellation cancels the booking and frees its slots. A rejection needs a reason.">
        <div class="grid gap-1">
            <label for="decision-note" class="text-xs font-medium text-ink-muted">Note</label>
            <textarea id="decision-note" wire:model="decisionNote" rows="3" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink shadow-xs focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none"></textarea>
            @error('note')<p class="text-xs text-danger">{{ $message }}</p>@enderror
        </div>
        <x-slot:footer>
            <x-ui.button variant="danger-ghost" wire:click="decide(false)">Reject</x-ui.button>
            <x-ui.button wire:click="decide(true)">Approve</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showPayout" title="Pay out refund" description="Record how the refund went out. Only completed refunds reduce what the booking has paid.">
        <form wire:submit="savePayout" id="payout-form" class="grid gap-3">
            <x-ui.select label="Outcome" wire:model.live="payout.outcome" id="payout-outcome">
                <option value="processing">Processing</option>
                <option value="completed">Completed (paid)</option>
                <option value="failed">Failed</option>
            </x-ui.select>
            @if ($payout['outcome'] === 'completed')
                <x-ui.select label="Paid by" wire:model="payout.method" id="payout-method">
                    @foreach (\App\Enums\Travel\PaymentMethod::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
                </x-ui.select>
                <x-ui.input label="M-Pesa or bank reference" wire:model="payout.reference" id="payout-reference" />
            @elseif ($payout['outcome'] === 'failed')
                <x-ui.input label="Why it failed" wire:model="payout.reason" id="payout-reason" />
            @endif
            @error('payout.outcome')<p class="text-xs text-danger">{{ $message }}</p>@enderror
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="payout-form">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
