@php
    $selectClass = 'h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
    $textarea = 'w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink placeholder:text-ink-subtle focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
@endphp

<div class="grid gap-5">
    <x-ui.page-header title="Trip reviews" description="What clients said after their trip, recorded by the team on the client's behalf. Hidden reviews stay on file but do not count towards ratings.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="building" :href="route('travel.providers.index')" wire:navigate>Providers</x-ui.button>
            <x-ui.button variant="secondary" icon="arrow-right" :href="route('travel.reviews.export', $exportQuery)">Export to Excel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line shadow-card md:grid-cols-4">
        @foreach ([
            ['Reviews', number_format($summary['count']), 'text-ink'],
            ['Average rating', $summary['count'] ? number_format((float) $summary['average'], 1).' / 5' : '—', 'text-ink'],
            ['Low ratings (2 or below)', number_format($summary['low']), $summary['low'] ? 'text-danger' : 'text-ink'],
            ['Recorded this month', number_format($summary['month']), 'text-ink'],
        ] as [$label, $value, $tone])
            <div class="grid gap-0.5 bg-surface px-4 py-3">
                <dt class="text-xs font-medium text-ink-subtle">{{ $label }}</dt>
                <dd class="tabular text-xl leading-tight font-bold {{ $tone }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="flex flex-wrap items-center gap-2 rounded-xl border border-line bg-surface p-3 shadow-card">
        <select wire:model.live="provider" aria-label="Provider" class="{{ $selectClass }} max-w-64">
            <option value="">All providers</option>
            @foreach ($providers as $option)
                <option value="{{ $option->id }}">{{ $option->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="package" aria-label="Package" class="{{ $selectClass }} max-w-64">
            <option value="">All packages</option>
            @foreach ($packages as $option)
                <option value="{{ $option->id }}">{{ $option->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="rating" aria-label="Rating" class="{{ $selectClass }}">
            <option value="">Any rating</option>
            <option value="low">Low (2 or below)</option>
            @foreach ([5, 4, 3, 2, 1] as $stars)
                <option value="{{ $stars }}">{{ $stars }} {{ $stars === 1 ? 'star' : 'stars' }}</option>
            @endforeach
        </select>
        <input type="date" wire:model.live="from" aria-label="Recorded from" class="{{ $selectClass }}">
        <input type="date" wire:model.live="to" aria-label="Recorded to" class="{{ $selectClass }}">
        @if ($hasFilters)
            <button type="button" wire:click="clearFilters" class="ml-1 text-[13px] font-medium text-brand-text hover:underline">Clear all</button>
        @endif
    </div>

    <x-ui.table-card :paginator="$reviews">
        <table class="w-full min-w-[1080px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="text-left">Recorded</th>
                    <th class="text-left">Provider / package</th>
                    <th class="text-left">Client</th>
                    <th class="text-left">Rating</th>
                    <th class="text-left">Comment</th>
                    <th class="text-left">Recorded by</th>
                    <th class="text-right"><span class="sr-only">Action</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($reviews as $review)
                    @php
                        $categories = collect(\App\Models\TripReview::Categories)
                            ->filter(fn ($label, $column) => $review->{$column} !== null)
                            ->map(fn ($label, $column) => $label.' '.$review->{$column});
                    @endphp
                    <tr wire:key="rev-{{ $review->id }}" @class(['bg-danger-soft/30' => $review->isLow() && ! $review->is_hidden, 'opacity-60' => $review->is_hidden])>
                        <td class="tabular whitespace-nowrap text-ink-muted">{{ $review->recorded_at->format('j M Y') }}</td>
                        <td class="min-w-44">
                            @if ($review->provider)<a href="{{ route('travel.providers.show', $review->travel_provider_id) }}" wire:navigate class="font-medium text-ink hover:text-brand-text">{{ $review->provider->name }}</a>@endif
                            <span class="block text-xs text-ink-subtle">{{ $review->package?->name }} · <a href="{{ route('travel.bookings.show', $review->package_booking_id) }}" wire:navigate class="hover:underline">{{ $review->booking?->reference }}</a></span>
                        </td>
                        <td class="whitespace-nowrap text-ink-muted">{{ $review->client?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap">
                            <span class="tabular font-semibold {{ $review->isLow() ? 'text-danger' : 'text-ink' }}"><span class="text-warning" aria-hidden="true">★</span> {{ $review->rating }}/5</span>
                            @if ($review->isLow())<x-ui.pill tone="danger" :dot="false">Low</x-ui.pill>@endif
                            @if ($review->is_hidden)<x-ui.pill tone="neutral" :dot="false">Hidden</x-ui.pill>@endif
                            @if ($categories->isNotEmpty())<span class="block text-xs text-ink-subtle">{{ $categories->implode(' · ') }}</span>@endif
                        </td>
                        <td class="max-w-md">
                            <span class="block text-ink">{{ $review->comment ?: '—' }}</span>
                            @if ($review->is_hidden)<span class="block text-xs text-ink-subtle">Hidden: {{ $review->hidden_reason }}</span>@endif
                        </td>
                        <td class="whitespace-nowrap text-ink-muted">{{ $review->recorder?->name }}<span class="block text-xs text-ink-subtle">{{ $review->source->label() }}</span></td>
                        <td class="text-right">
                            @if ($canModerate)
                                @if ($review->is_hidden)
                                    <x-ui.button size="sm" variant="secondary" wire:click="unhide({{ $review->id }})">Show again</x-ui.button>
                                @else
                                    <x-ui.button size="sm" variant="secondary" wire:click="openHide({{ $review->id }})">Hide</x-ui.button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-ui.empty-state icon="check-circle" title="No reviews match these filters" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    <x-ui.modal wire:model="showHide" title="Hide review" description="It stays on file but stops counting towards the provider and package ratings." maxWidth="max-w-md">
        <form wire:submit="hide" id="hide-review-form" class="grid gap-1">
            <label for="hide-reason" class="text-xs font-medium text-ink-muted">Reason *</label>
            <textarea id="hide-reason" rows="3" wire:model="reason" class="{{ $textarea }}" placeholder="e.g. Duplicate, abusive language, wrong booking"></textarea>
            @error('reason')<p class="text-xs text-danger">{{ $message }}</p>@enderror
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="hide-review-form">Hide review</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
