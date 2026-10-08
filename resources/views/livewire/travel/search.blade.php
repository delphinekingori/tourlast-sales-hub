<div class="grid gap-5">
    <x-ui.page-header eyebrow="Travel Sales" title="Search" description="Flight bookings and passengers, providers, contracts, packages, package bookings, clients, payments, drivers, guides and influencer codes." />

    <div class="rounded-xl border border-line bg-surface p-3 shadow-card">
        <x-ui.search wire:model.live.debounce.300ms="q" placeholder="Search a provider, package, booking ref, client, PNR, receipt or code…" :wide="true" autofocus />
    </div>

    <div class="grid gap-4" wire:loading.delay.class="opacity-60">
        @if ($tooShort)
            <x-ui.empty-state icon="search" title="Type at least two characters" description="For example a provider name like “ABC Safaris”, a booking reference, a PNR or an M-Pesa receipt." />
        @elseif ($groups === [])
            <x-ui.empty-state icon="search" title="Nothing found" :description="'No travel records match “'.$q.'” that you can open.'" />
        @else
            <p class="text-[13px] text-ink-muted">{{ number_format($total) }} {{ \Illuminate\Support\Str::plural('result', $total) }} for “{{ $q }}”</p>
            <div class="grid items-start gap-4 xl:grid-cols-2">
                @foreach ($groups as $group)
                    <x-ui.card :title="$group['label'].' ('.$group['total'].')'" :padding="false" wire:key="group-{{ $group['key'] }}">
                        @foreach ($group['items'] as $item)
                            @php($tag = $item['url'] ? 'a' : 'div')
                            <{{ $tag }} @if ($item['url']) href="{{ $item['url'] }}" wire:navigate @endif class="flex items-center gap-3 border-b border-line px-4 py-2.5 last:border-b-0 hover:bg-surface-muted/50">
                                <span class="grid size-7 shrink-0 place-items-center rounded-full bg-brand-soft text-brand-text"><x-ui.icon :name="$group['icon']" class="size-3.5" /></span>
                                <span class="grid min-w-0 flex-1 leading-tight">
                                    <span class="truncate text-[13px] font-semibold text-ink">{{ $item['title'] }}</span>
                                    <span class="truncate text-xs text-ink-muted">{{ $item['meta'] }}</span>
                                </span>
                                @if ($item['badge'])
                                    <x-ui.pill :tone="$item['tone']">{{ $item['badge'] }}</x-ui.pill>
                                @endif
                            </{{ $tag }}>
                        @endforeach
                        @if ($group['more'])
                            <a href="{{ $group['more'] }}" wire:navigate class="block px-4 py-2 text-xs font-medium text-brand-text hover:underline">See all {{ $group['total'] }}</a>
                        @endif
                    </x-ui.card>
                @endforeach
            </div>
        @endif
    </div>
</div>
