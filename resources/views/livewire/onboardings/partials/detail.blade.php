<div class="grid gap-5">
    <div class="flex flex-wrap items-center gap-2">
        <x-ui.pill :tone="$onboarding->status->tone()">{{ $onboarding->status->label() }}</x-ui.pill>
        @if ($onboarding->credited_at)
            <x-ui.pill tone="success" :dot="false">Counted {{ $onboarding->credited_at->format('F Y') }}</x-ui.pill>
        @endif
        @if ($onboarding->attribution === 'manual')
            <x-ui.pill tone="warning" :dot="false">Assigned by admin</x-ui.pill>
        @endif
    </div>

    <div class="rounded-lg border border-line px-2 py-3">
        <x-ui.onboarding-steps :onboarding="$onboarding" />
    </div>

    <dl class="grid gap-3 rounded-xl border border-line p-4 text-sm">
        <div class="flex justify-between gap-4"><dt class="text-ink-subtle">tourlast.com ID</dt><dd class="font-mono text-[13px] text-ink">{{ $onboarding->tourlast_property_id }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Referral code</dt><dd class="font-mono text-[13px] text-ink">{{ $onboarding->ref_code ?? '—' }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Contact</dt><dd class="text-right text-ink">{{ $onboarding->contact_name ?? '—' }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Phone</dt><dd class="text-ink">{{ $onboarding->contact_phone ?? '—' }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-ink-subtle">Email</dt><dd class="truncate text-ink">{{ $onboarding->contact_email ?? '—' }}</dd></div>
    </dl>

    <div class="grid gap-3">
        <h3 class="text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Status history</h3>
        <ol class="grid gap-0">
            @forelse ($onboarding->statusChanges as $change)
                <li wire:key="sc-{{ $change->id }}" class="relative grid grid-cols-[20px_1fr] gap-3 pb-4 last:pb-0">
                    <span class="relative z-10 mt-1 size-2.5 rounded-full bg-brand ring-4 ring-brand-soft"></span>
                    @unless ($loop->last)<span class="absolute top-4 bottom-0 left-[4px] w-px bg-line"></span>@endunless
                    <div class="grid leading-tight">
                        <span class="font-semibold text-ink">{{ $change->to_status->label() }}</span>
                        <span class="text-[13px] text-ink-subtle">{{ $change->occurred_at->format('j M Y, H:i') }} · from tourlast.com</span>
                    </div>
                </li>
            @empty
                <li class="text-[13px] text-ink-subtle">No status changes recorded yet.</li>
            @endforelse
        </ol>
    </div>

    @if ($onboarding->attributionChanges->isNotEmpty())
        <div class="grid gap-3">
            <h3 class="text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">Credit changes</h3>
            @foreach ($onboarding->attributionChanges as $change)
                <div wire:key="ac-{{ $change->id }}" class="rounded-lg bg-surface-muted p-3 text-[13px]">
                    <p class="text-ink">Assigned by {{ $change->changedBy->name }} on {{ $change->created_at->format('j M Y') }}</p>
                    <p class="text-ink-muted">“{{ $change->reason }}”</p>
                </div>
            @endforeach
        </div>
    @endif
</div>
