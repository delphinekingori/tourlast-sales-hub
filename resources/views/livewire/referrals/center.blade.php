@php
    $funnel = [
        ['Link visits', $metrics['clicks']],
        ['Applications', $metrics['submitted']],
        ['Approved', $approvedInPeriod],
        ['Live', $metrics['onboarded']],
        ['Active', $metrics['active']],
    ];
    $funnelTop = max(1, collect($funnel)->max(fn ($step) => $step[1]));
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="Referral Center" description="Your permanent referral link and everything that came through it. Providers sign up on tourlast.com; the Hub credits them to you automatically.">
        <x-slot:actions>
            <x-ui.segmented wire:model.live="period" :options="\App\Support\Period::options()" />
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]" wire:loading.delay.class="opacity-50" wire:target="period">
        <x-referral-card :referral-code="$referralCode" />

        <div class="grid min-w-0 gap-4">
            <x-ui.card title="Referral funnel" :description="$range->label()">
                <div class="grid gap-4">
                    <div class="grid grid-cols-3 gap-px overflow-hidden rounded-md border border-line bg-line sm:grid-cols-5">
                        @foreach ($funnel as [$label, $value])
                            <div class="grid min-w-0 gap-0.5 bg-surface px-3 py-2 last:col-span-2 sm:last:col-span-1">
                                <span class="text-[11px] leading-tight font-medium text-ink-subtle">{{ $label }}</span>
                                <span class="tabular text-xl leading-tight font-bold text-ink">{{ $value }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="grid gap-1.5" aria-label="Conversion funnel">
                        @foreach ($funnel as [$label, $value])
                            <div class="grid grid-cols-[96px_1fr_36px] items-center gap-2 text-xs">
                                <span class="text-ink-muted">{{ $label }}</span>
                                <div class="h-2 overflow-hidden rounded-full bg-surface-muted"><div class="h-full rounded-full bg-brand" style="width: {{ round($value / $funnelTop * 100) }}%"></div></div>
                                <span class="tabular text-right font-medium text-ink">{{ $value }}</span>
                            </div>
                        @endforeach
                    </div>
                    @if ($metrics['conversion'] !== null)
                        <p class="text-xs text-ink-muted">{{ $metrics['conversion'] }}% of link visits became live partners this period.</p>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card title="Latest referred providers" :padding="false">
                <x-slot:actions>
                    <x-ui.button :href="route('onboardings.mine')" variant="ghost" size="sm" wire:navigate>My Onboardings</x-ui.button>
                </x-slot:actions>
                @forelse ($recent as $onboarding)
                    <div wire:key="rc-{{ $onboarding->id }}" class="flex items-center justify-between gap-3 border-b border-line px-4 py-2.5 last:border-b-0">
                        <span class="grid min-w-0 leading-tight">
                            <span class="truncate text-[13px] font-medium text-ink">{{ $onboarding->property_name }}</span>
                            <span class="truncate text-xs text-ink-subtle">{{ $onboarding->propertyTypeLabel() }}{{ $onboarding->location ? ' · '.$onboarding->location : '' }} · signed up {{ $onboarding->submitted_at?->format('j M Y') }}</span>
                        </span>
                        <span class="flex shrink-0 items-center gap-2">
                            <x-ui.onboarding-steps :onboarding="$onboarding" compact class="hidden sm:flex" />
                            <x-ui.pill :tone="$onboarding->status->tone()">{{ $onboarding->status->label() }}</x-ui.pill>
                        </span>
                    </div>
                @empty
                    <x-ui.empty-state icon="link" title="No referred signups yet" description="Share your link. Every provider who signs up through it appears here." />
                @endforelse
            </x-ui.card>
        </div>
    </div>
</div>
