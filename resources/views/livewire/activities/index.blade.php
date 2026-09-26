<div class="grid gap-5">
    <x-ui.page-header eyebrow="Me" title="Activities & Schedule" :description="'What needs doing today, and what you\'ve done recently. '.$weekCount.' '.\Illuminate\Support\Str::plural('activity', $weekCount).' logged this week.'">
        <x-slot:actions>
            <x-ui.button :href="route('calendar.index')" variant="secondary" icon="calendar" wire:navigate>Calendar</x-ui.button>
            <x-ui.button icon="plus" x-on:click="$dispatch('open-schedule')">Schedule</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat label="Overdue" :value="$overdue->count()" hint="Past their due date" />
        <x-ui.stat label="Due today" :value="$today->count()" />
        <x-ui.stat label="Coming up" :value="$upcoming->count()" hint="Scheduled after today" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="grid content-start gap-4">
            @foreach (['Overdue' => $overdue, 'Today' => $today, 'Coming up' => $upcoming] as $heading => $items)
                <x-ui.card :title="$heading" :padding="false">
                    @forelse ($items as $item)
                        <div wire:key="fu-{{ $item->id }}" class="flex items-center justify-between gap-4 border-b border-line px-4 py-2.5 last:border-b-0">
                            <a href="{{ route('leads.show', $item->lead) }}" wire:navigate class="flex min-w-0 items-center gap-3">
                                <span @class(['grid size-8 shrink-0 place-items-center rounded-full', 'bg-brand-soft text-brand-text' => $item->isMeeting(), 'bg-surface-muted text-ink-muted' => ! $item->isMeeting()])><x-ui.icon :name="$item->type->icon()" class="size-4" /></span>
                                <span class="grid min-w-0 leading-tight">
                                    <span class="truncate font-semibold text-ink hover:text-brand-text">{{ $item->lead->business_name }}</span>
                                    <span class="truncate text-[13px] text-ink-subtle">{{ $item->type->label() }} — {{ $item->task }} · {{ $item->due_at->format('D j M') }} · {{ $item->timeLabel() }}{{ $item->contact_name ? ' · '.$item->contact_name : '' }}</span>
                                </span>
                            </a>
                            <x-ui.button size="sm" variant="secondary" icon="check" x-on:click="$dispatch('open-schedule', { itemId: {{ $item->id }}, complete: true })">Done</x-ui.button>
                        </div>
                    @empty
                        <p class="px-4 py-3 text-[13px] text-ink-subtle">Nothing {{ strtolower($heading) }}.</p>
                    @endforelse
                </x-ui.card>
            @endforeach
        </div>

        <x-ui.card title="Recent activity" :padding="false">
            @forelse ($recent as $item)
                <a wire:key="rec-{{ $item->id }}" href="{{ route('leads.show', $item->lead) }}" wire:navigate class="flex items-start gap-3 border-b border-line px-4 py-2.5 last:border-b-0 hover:bg-surface-muted/60">
                    <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-brand-soft text-brand-text"><x-ui.icon :name="$item->type->icon()" class="size-4" /></span>
                    <span class="grid min-w-0 leading-tight">
                        <span class="text-sm"><span class="font-semibold text-ink">{{ $item->type->label() }}</span> <span class="text-ink-muted">with {{ $item->lead->business_name }}</span></span>
                        <span class="text-[13px] text-ink-subtle">{{ $item->happened_at->diffForHumans() }}{{ $item->notes ? ' · '.\Illuminate\Support\Str::limit($item->notes, 70) : '' }}</span>
                    </span>
                </a>
            @empty
                <x-ui.empty-state icon="activity" title="No activity yet" description="Open a lead and log your first call, visit or message." />
            @endforelse
        </x-ui.card>
    </div>
</div>
