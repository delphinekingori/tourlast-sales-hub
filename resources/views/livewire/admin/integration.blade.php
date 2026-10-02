<div class="grid gap-5">
    <x-ui.page-header eyebrow="Admin" title="Integration" description="How the Hub reads referred providers from tourlast.com. The Hub only reads; it never changes anything on tourlast.com.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="refresh" wire:click="syncNow(true)" wire:loading.attr="disabled">Full re-check</x-ui.button>
            <x-ui.button icon="refresh" wire:click="syncNow" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="syncNow">Sync now</span>
                <span wire:loading wire:target="syncNow">Syncing…</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 md:grid-cols-3">
        <x-ui.card>
            <p class="text-[13px] font-semibold text-ink-subtle">Data source</p>
            <p class="mt-1 text-xl font-bold text-ink capitalize">{{ $source }}</p>
            <p class="mt-1 text-[13px] text-ink-muted">
                @switch($source)
                    @case('sandbox') Sample data kept in this app. Switch to <span class="font-mono text-xs">database</span> or <span class="font-mono text-xs">api</span> once tourlast.com access is ready. @break
                    @case('database') Read-only connection to the tourlast.com database. @break
                    @case('api') Read-only API on tourlast.com. @break
                @endswitch
            </p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-[13px] font-semibold text-ink-subtle">Last successful sync</p>
            <p class="mt-1 text-xl font-bold text-ink">{{ $lastSuccess?->finished_at?->diffForHumans() ?? 'Never' }}</p>
            <p class="mt-1 text-[13px] text-ink-muted">Runs automatically every {{ config('tourlast.sync_every_minutes') }} minutes, with a full re-check nightly at {{ config('tourlast.full_sync_at') }}.</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-[13px] font-semibold text-ink-subtle">Instant updates (webhook)</p>
            <p class="mt-1">@if ($webhookEnabled)<x-ui.pill tone="success">Enabled</x-ui.pill>@else<x-ui.pill>Not enabled</x-ui.pill>@endif</p>
            <p class="mt-2 truncate font-mono text-xs text-ink-muted" title="{{ $webhookUrl }}">{{ $webhookUrl }}</p>
        </x-ui.card>
    </div>

    @if ($isSandbox)
        <x-ui.card title="Simulator" description="Create sample signups as if a provider used a referral link on tourlast.com, then move them through review. Each change runs a sync, so every screen updates just as it will with real data.">
            <x-slot:actions>
                <x-ui.button size="sm" icon="plus" wire:click="openSample">New sample signup</x-ui.button>
            </x-slot:actions>

            @if ($sandboxProviders->isEmpty())
                <x-ui.empty-state icon="building" title="No sample signups yet" description="Create one to see it arrive in the salesperson's My Onboardings and progress." />
            @else
                <div class="-mx-5 -mb-5 overflow-x-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead class="text-left text-ink-subtle uppercase">
                            <tr><th class="text-left">Property</th><th class="text-left">Referral code</th><th class="text-left">Status</th><th class="text-right">Move to</th></tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($sandboxProviders as $provider)
                                @php($status = \App\Enums\OnboardingStatus::from($provider->status))
                                <tr wire:key="sbx-{{ $provider->id }}">
                                    <td><div class="grid leading-tight"><span class="font-semibold text-ink">{{ $provider->property_name }}</span><span class="font-mono text-xs text-ink-subtle">{{ $provider->property_id }}</span></div></td>
                                    <td class="font-mono text-[13px] text-brand-text">{{ $provider->ref_code ?? '— none —' }}</td>
                                    <td><x-ui.pill :tone="$status->tone()">{{ $status->label() }}</x-ui.pill></td>
                                    <td>
                                        <div class="flex flex-wrap justify-end gap-1">
                                            @if ($status === \App\Enums\OnboardingStatus::Active && ! $provider->first_booking_at)
                                                <x-ui.button size="sm" variant="ghost" wire:click="recordBooking({{ $provider->id }})">First booking</x-ui.button>
                                            @endif
                                            @foreach ($statuses as $next)
                                                @continue($next === $status || $next === \App\Enums\OnboardingStatus::Submitted)
                                                <x-ui.button size="sm" :variant="$next === \App\Enums\OnboardingStatus::Rejected ? 'danger-ghost' : 'ghost'" wire:click="advance({{ $provider->id }}, '{{ $next->value }}')">{{ $next->label() }}</x-ui.button>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ui.card title="Sync log" :padding="false">
            @forelse ($runs as $run)
                <div wire:key="run-{{ $run->id }}" class="flex items-start justify-between gap-4 border-b border-line px-4 py-2.5 last:border-b-0">
                    <div class="grid leading-tight">
                        <span class="text-sm font-semibold text-ink">{{ ucfirst($run->mode) }} · {{ $run->source }}</span>
                        <span class="text-[13px] text-ink-subtle">{{ $run->started_at->format('j M, H:i:s') }} · {{ $run->records_seen }} seen, {{ $run->records_created }} new, {{ $run->records_updated }} updated@if ($run->records_deleted > 0), {{ $run->records_deleted }} deleted@endif</span>
                        @if ($run->error)<span class="mt-1 text-[13px] text-danger">{{ $run->error }}</span>@endif
                    </div>
                    <x-ui.pill :tone="$run->status === 'succeeded' ? 'success' : ($run->status === 'failed' ? 'danger' : 'warning')">{{ ucfirst($run->status) }}</x-ui.pill>
                </div>
            @empty
                <x-ui.empty-state icon="refresh" title="No syncs yet" description="Press Sync now, or wait for the scheduler." />
            @endforelse
        </x-ui.card>

        <x-ui.card title="Latest status changes" :padding="false">
            @forelse ($recentChanges as $change)
                <div wire:key="chg-{{ $change->id }}" class="flex items-center justify-between gap-4 border-b border-line px-4 py-2.5 last:border-b-0">
                    <div class="grid min-w-0 leading-tight">
                        <span class="truncate text-sm font-semibold text-ink">{{ $change->onboarding->property_name }}</span>
                        <span class="text-[13px] text-ink-subtle">{{ $change->onboarding->user?->name ?? 'Unattributed' }} · {{ $change->occurred_at->diffForHumans() }} · via {{ $change->source }}</span>
                    </div>
                    <x-ui.pill :tone="$change->to_status->tone()">{{ $change->to_status->label() }}</x-ui.pill>
                </div>
            @empty
                <x-ui.empty-state icon="activity" title="No changes recorded yet" />
            @endforelse
        </x-ui.card>
    </div>

    <x-ui.card title="Deleted properties" description="Reported as deleted on tourlast.com. The credit they earned stays with the salesperson who referred them." :padding="false">
        @forelse ($deleted as $onboarding)
            <div wire:key="deleted-{{ $onboarding->id }}" class="flex items-center justify-between gap-4 border-b border-line px-4 py-2.5 last:border-b-0">
                <div class="grid min-w-0 leading-tight">
                    <span class="truncate text-sm font-semibold text-ink">{{ $onboarding->property_name }}</span>
                    <span class="text-[13px] text-ink-subtle">{{ $onboarding->user?->name ?? 'Unattributed' }} · {{ $onboarding->deleted_at?->format('j M Y, H:i') }} · credit kept</span>
                </div>
                <div class="flex items-center gap-2">
                    <x-ui.pill tone="warning" :dot="false">Deleted</x-ui.pill>
                    <x-ui.button size="sm" variant="secondary" wire:click="restore({{ $onboarding->id }})" wire:loading.attr="disabled">Restore</x-ui.button>
                </div>
            </div>
        @empty
            <x-ui.empty-state icon="building" title="Nothing deleted" description="Properties the source app deletes will be listed here, with their credit kept." />
        @endforelse
    </x-ui.card>

    @if ($isSandbox)
        <x-ui.slide-over wire:model="showSample" title="New sample signup" description="Pretend a provider just completed List Your Property on tourlast.com.">
            <form id="sample-form" wire:submit="createSample" class="grid gap-4">
                <x-ui.select label="Came through referral code" wire:model="sample.ref_code" id="sample-ref">
                    <option value="">No code (unattributed)</option>
                    @foreach ($referralCodes as $code)
                        <option value="{{ $code->code }}">{{ $code->code }} · {{ $code->user->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Property name" wire:model="sample.property_name" id="sample-name" placeholder="e.g. Sarova Whitesands Beach Resort" />
                <x-ui.select label="Type" wire:model="sample.property_type" id="sample-type">
                    @foreach (config('hub.property_types') as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Location" wire:model="sample.location" id="sample-location" placeholder="e.g. Mombasa, Kenya" />
                <x-ui.input label="Contact name" wire:model="sample.contact_name" id="sample-contact" />
                <x-ui.input label="Contact phone" wire:model="sample.contact_phone" id="sample-phone" hint="Matching a lead's phone or email links the signup to that lead." />
                <x-ui.input label="Contact email" type="email" wire:model="sample.contact_email" id="sample-email" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="sample-form">Create and sync</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>
    @endif
</div>
