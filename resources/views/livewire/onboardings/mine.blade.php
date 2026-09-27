<div class="grid gap-5">
    <x-ui.page-header
        eyebrow="Me"
        title="My Onboardings"
        description="Every provider that signed up on tourlast.com through your referral link, with its latest status from tourlast.com."
    />

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex flex-wrap rounded-md border border-line bg-surface p-0.5">
            @foreach (['all' => 'All', 'awaiting' => 'Not live yet', 'onboarded' => 'Live', 'rejected' => 'Rejected'] as $key => $label)
                <button type="button" wire:click="$set('filter', '{{ $key }}')" wire:key="filter-{{ $key }}"
                    @class(['flex items-center gap-2 rounded px-2.5 py-1 text-xs font-medium', 'bg-brand-soft text-brand-text' => $filter === $key, 'text-ink-subtle hover:text-ink' => $filter !== $key])>
                    {{ $label }} <span class="tabular text-xs opacity-70">{{ $counts[$key] }}</span>
                </button>
            @endforeach
        </div>
        <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search property, location or contact" />
    </div>

    <x-ui.table-card :paginator="$onboardings">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Property</th>
                    <th class="text-left">Type</th>
                    <th class="text-left">Signed up</th>
                    <th class="text-left">Onboarded</th>
                    <th class="text-left">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($onboardings as $onboarding)
                    <tr wire:key="ob-{{ $onboarding->id }}" wire:click="view({{ $onboarding->id }})" class="cursor-pointer hover:bg-surface-muted/60">
                        <td>
                            <div class="grid leading-tight">
                                <span class="font-semibold text-ink">{{ $onboarding->property_name }}</span>
                                <span class="text-[13px] text-ink-subtle">{{ $onboarding->location ?? '—' }}</span>
                            </div>
                        </td>
                        <td class="text-ink-muted">{{ $onboarding->propertyTypeLabel() }}</td>
                        <td class="text-ink-muted">{{ $onboarding->submitted_at?->format('j M Y') }}</td>
                        <td class="text-ink-muted">{{ $onboarding->credited_at?->format('j M Y') ?? '—' }}</td>
                        <td>
                            <div class="flex items-center gap-2">
                                <x-ui.onboarding-steps :onboarding="$onboarding" compact class="hidden xl:flex" />
                                <x-ui.pill :tone="$onboarding->status->tone()">{{ $onboarding->status->label() }}</x-ui.pill>
                                @if ($onboarding->isStalled())<x-ui.pill tone="danger" :dot="false">Stalled</x-ui.pill>@endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">
                        <x-ui.empty-state icon="building" title="No onboardings here yet" description="When a provider signs up on tourlast.com through your link, they appear here within minutes." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    <x-ui.slide-over wire:model="showDetail" :title="$viewing?->property_name ?? 'Onboarding'" :description="$viewing ? $viewing->propertyTypeLabel().($viewing->location ? ' · '.$viewing->location : '') : null">
        @if ($viewing)
            @include('livewire.onboardings.partials.detail', ['onboarding' => $viewing])
        @endif
    </x-ui.slide-over>
</div>
