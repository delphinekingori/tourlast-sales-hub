{{-- Freshness of the Hub's copy of Flights Super Admin. Stale data is always labelled. --}}
<div class="grid gap-2">
    @if ($sync->isStale())
        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-warning/40 bg-warning-soft/50 px-4 py-2.5 text-[13px] text-ink" role="status">
            <x-ui.icon name="alert" class="size-4 text-warning" />
            @if ($sync->lastSyncedAt())
                Flight data synchronization delayed — showing data from {{ $sync->lastSyncedAt()->format('j M Y, H:i') }}.
            @else
                Flight data has not been synchronized yet — nothing below is live.
            @endif
            @if ($managesAll && $sync->lastError())
                <span class="text-ink-subtle">Last error: {{ \Illuminate\Support\Str::limit($sync->lastError(), 160) }}</span>
            @endif
        </div>
    @endif
    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-subtle">
        <x-ui.icon name="refresh" class="size-3.5" />
        <span>{{ $sync->lastSyncedAt() ? 'Last synced '.$sync->lastSyncedAt()->diffForHumans() : 'Never synced' }}</span>
        <span>· Source: {{ $sync->sourceLabel() }}</span>
        @if ($sync->isSandbox())
            <x-ui.pill tone="warning" :dot="false">Test data — the Flights Super Admin connection isn't live yet.</x-ui.pill>
        @endif
    </p>
</div>
