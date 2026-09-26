<div class="grid gap-5">
    <div class="flex flex-wrap items-center gap-2">
        <x-ui.pill :tone="$claim->statusTone()">{{ $claim->statusLabel() }}</x-ui.pill>
        <span class="tabular text-lg font-bold text-ink">KES {{ number_format($claim->payableAmount(), 2) }}</span>
        @if ($claim->approved_amount !== null && abs($claim->approved_amount - $claim->amount) > 0.001)
            <span class="text-[13px] text-ink-subtle line-through">KES {{ number_format($claim->amount, 2) }} requested</span>
        @endif
    </div>

    <dl class="grid gap-3 rounded-xl border border-line p-4 text-sm">
        <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Salesperson</dt><dd class="text-ink">{{ $claim->user->name }}</dd></div>
        @if ($claim->isTransport())
            <div class="flex justify-between gap-4"><dt class="text-ink-subtle">{{ $claim->isRequest() ? 'Planned date' : 'Travel date' }}</dt><dd class="text-ink">{{ $claim->travel_date?->format('D j M Y') }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Route</dt><dd class="text-right text-ink">{{ $claim->pickup }} → {{ $claim->dropoff }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Travelled by</dt><dd class="text-ink">{{ $claim->rideProviderLabel() }}</dd></div>
            @if ($claim->trip_reference)
                <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Trip ID</dt><dd class="font-mono text-[13px] text-ink select-all">{{ $claim->trip_reference }}</dd></div>
            @endif
            @if ($claim->distance_km)
                <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Distance</dt><dd class="text-ink">{{ rtrim(rtrim(number_format($claim->distance_km, 1), '0'), '.') }} km</dd></div>
            @endif
            @if ($claim->partnerAccount || $claim->lead)
                <div class="flex justify-between gap-4"><dt class="text-ink-subtle">For</dt><dd class="text-right text-ink">{{ $claim->partnerAccount?->legal_name ?? $claim->lead?->business_name }}</dd></div>
            @endif
        @endif
        <div class="grid gap-1"><dt class="text-ink-subtle">Purpose</dt><dd class="whitespace-pre-line text-ink">{{ $claim->description }}</dd></div>
        @if ($claim->payment_reference)
            <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Payment reference</dt><dd class="font-mono text-[13px] text-ink">{{ $claim->payment_reference }} · {{ $claim->paid_at?->format('j M') }}</dd></div>
        @endif
    </dl>

    <div class="grid gap-2">
        <h3 class="text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Files</h3>
        @forelse ($claim->attachments as $file)
            <a wire:key="file-{{ $file->id }}" href="{{ route('downloads.claim-attachment', $file) }}" target="_blank" class="flex items-center justify-between gap-3 rounded-lg border border-line px-3 py-2 text-sm hover:bg-surface-muted/60">
                <span class="grid min-w-0 leading-tight"><span class="truncate font-semibold text-ink">{{ $file->original_name }}</span><span class="text-xs text-ink-subtle">{{ $file->kindLabel() }}</span></span>
                <x-ui.icon name="arrow-right" class="size-4 text-ink-subtle" />
            </a>
        @empty
            <p class="text-[13px] text-ink-subtle">No files attached.</p>
        @endforelse
        @if ($claim->usesRideApp() && ! $claim->isRequest() && $claim->attachments->where('kind', 'ride_details')->isEmpty())
            <p class="rounded-lg bg-warning-soft px-3 py-2 text-[13px] text-warning">No Bolt/Uber ride details attached yet.</p>
        @endif
    </div>

    <div class="grid gap-3">
        <h3 class="text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Approvals</h3>
        <ol class="grid gap-3">
            @foreach ($claim->steps() as $step)
                @php($decision = $claim->approvals->where('step', $step)->last())
                <li wire:key="step-{{ $step }}" class="flex items-start gap-3">
                    <span @class([
                        'mt-0.5 grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold',
                        'bg-success-soft text-success' => $decision?->decision === 'approved',
                        'bg-danger-soft text-danger' => $decision?->decision === 'rejected',
                        'bg-brand-soft text-brand-text' => ! $decision && $claim->current_step === $step,
                        'bg-surface-muted text-ink-subtle' => ! $decision && $claim->current_step !== $step,
                    ])>{{ $loop->iteration }}</span>
                    <div class="grid leading-tight">
                        <span class="text-sm font-semibold text-ink">{{ \App\Models\ExpenseClaim::StepLabels[$step] }}</span>
                        <span class="text-[13px] text-ink-subtle">
                            @if ($decision)
                                {{ ucfirst($decision->decision) }} by {{ $decision->user->name }} · {{ $decision->created_at->format('j M, H:i') }}{{ $decision->amount !== null && $step === 'finance' ? ' · KES '.number_format($decision->amount, 2) : '' }}
                            @elseif ($claim->current_step === $step)
                                Waiting
                            @else
                                —
                            @endif
                        </span>
                        @if ($decision?->note)<span class="mt-1 text-[13px] text-ink">“{{ $decision->note }}”</span>@endif
                    </div>
                </li>
            @endforeach
        </ol>
    </div>
</div>
