<div class="grid gap-5" wire:init="check">
    <x-ui.page-header eyebrow="Admin" title="Connected apps" description="Every source app the Hub reads from, and whether each one answers right now. The Hub only reads; it never changes anything on a source app.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="link" href="{{ route('admin.integration') }}">Integration</x-ui.button>
            <x-ui.button icon="refresh" wire:click="check" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="check">Check again</span>
                <span wire:loading wire:target="check">Checking…</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 md:grid-cols-3">
        <x-ui.card>
            <p class="text-[13px] font-semibold text-ink-subtle">Apps online</p>
            <p class="mt-1 text-xl font-bold text-ink">{{ $checkedAt ? "{$online} of ".count($apps) : 'Checking…' }}</p>
            <p class="mt-1 text-[13px] text-ink-muted">{{ $checkedAt ? 'Last checked '.$checkedAt : 'Probing every configured app now.' }}</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-[13px] font-semibold text-ink-subtle">Providers sync</p>
            <p class="mt-1 text-xl font-bold text-ink">{{ $lastProviderSync?->finished_at?->diffForHumans() ?? 'Never' }}</p>
            <p class="mt-1 text-[13px] text-ink-muted">Reads referred providers every {{ config('tourlast.sync_every_minutes') }} minutes.</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-[13px] font-semibold text-ink-subtle">Flights sync</p>
            <p class="mt-1 text-xl font-bold text-ink">{{ $flightsSync->lastSyncedAt()?->diffForHumans() ?? 'Never' }}</p>
            <p class="mt-1 text-[13px] text-ink-muted">{{ $flightsSync->sourceLabel() }} · {{ $flightsSync->isStale() ? 'stale' : 'fresh' }}</p>
        </x-ui.card>
    </div>

    <x-ui.card title="Source apps" description="One row per feed the Hub is configured to read from." :padding="false">
        @foreach ($apps as $app)
            @php
                $pill = match ($app['state']) {
                    'online' => ['tone' => 'success', 'label' => 'Online'],
                    'offline' => ['tone' => 'danger', 'label' => 'Offline'],
                    'rejected' => ['tone' => 'warning', 'label' => 'Token rejected'],
                    'missing' => ['tone' => 'danger', 'label' => 'Feed missing'],
                    'error' => ['tone' => 'danger', 'label' => 'HTTP error'],
                    'no_token' => ['tone' => 'warning', 'label' => 'No token'],
                    'push' => ['tone' => 'brand', 'label' => 'Push mode'],
                    'sandbox' => ['tone' => 'neutral', 'label' => 'Sample data'],
                    'not_connected' => ['tone' => 'neutral', 'label' => 'Not connected'],
                    default => ['tone' => 'neutral', 'label' => 'Checking…'],
                };
                $detailTone = match ($app['state']) {
                    'online' => 'text-success',
                    'offline', 'missing', 'error' => 'text-danger',
                    'rejected', 'no_token' => 'text-warning',
                    default => 'text-ink-muted',
                };
            @endphp
            <div wire:key="app-{{ $app['key'] }}" class="flex items-start justify-between gap-4 border-b border-line px-4 py-3 last:border-b-0">
                <div class="grid min-w-0 gap-0.5 leading-tight">
                    <span class="text-sm font-semibold text-ink">{{ $app['name'] }}</span>
                    <span class="text-[13px] text-ink-subtle">{{ $app['feed'] }} · {{ $app['mode'] }} mode</span>
                    @if ($app['url'])
                        <span class="truncate font-mono text-xs text-ink-muted" title="{{ $app['url'] }}">{{ $app['url'] }}</span>
                    @endif
                    <span class="text-[13px] {{ $detailTone }}">{{ $app['detail'] }}</span>
                </div>
                <x-ui.pill :tone="$pill['tone']">{{ $pill['label'] }}</x-ui.pill>
            </div>
        @endforeach
    </x-ui.card>
</div>
