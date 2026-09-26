@php
    $label = match ($view) {
        'day' => $anchor->format('l, j F Y'),
        'month' => $anchor->format('F Y'),
        default => $from->format('j M').' – '.$to->format('j M Y'),
    };
@endphp

<div class="grid gap-4">
    <x-ui.page-header title="Calendar" description="Scheduled calls, meetings and site visits with properties. Completed items keep their outcome in the lead's and the property's history.">
        <x-slot:actions>
            @if ($sells)
                <x-ui.button icon="plus" x-on:click="$dispatch('open-schedule', { date: @js($view === 'day' ? $anchor->toDateString() : null) })">Schedule</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-wrap items-center gap-2 rounded-xl border border-line bg-surface p-2.5 shadow-card">
        <div class="inline-flex items-center rounded-md border border-line-strong">
            <button type="button" wire:click="move(-1)" class="grid size-8 place-items-center text-ink-muted hover:bg-surface-muted hover:text-ink" aria-label="Previous">‹</button>
            <button type="button" wire:click="today" class="h-8 border-x border-line-strong px-3 text-[13px] font-medium text-ink hover:bg-surface-muted">Today</button>
            <button type="button" wire:click="move(1)" class="grid size-8 place-items-center text-ink-muted hover:bg-surface-muted hover:text-ink" aria-label="Next">›</button>
        </div>
        <h2 class="mr-auto px-1 text-base font-bold text-ink">{{ $label }}</h2>
        <span class="text-xs text-ink-subtle">{{ $total }} scheduled · {{ $meetings }} {{ \Illuminate\Support\Str::plural('meeting', $meetings) }} / visits</span>
        @if ($seesTeam)
            <select wire:model.live="rep" aria-label="Whose calendar" class="h-8 rounded-md border border-line-strong bg-surface px-2.5 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                @if ($sells)
                    <option value="">My calendar</option>
                    <option value="team">Whole team</option>
                @else
                    <option value="">Whole team</option>
                @endif
                @foreach ($salespeople as $person)
                    @if ($person->id !== auth()->id())
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endif
                @endforeach
            </select>
        @endif
        <x-ui.segmented wire:model.live="view" :options="['day' => 'Day', 'week' => 'Week', 'month' => 'Month']" />
    </div>

    <div wire:loading.delay.class="opacity-60" class="transition-opacity">
        @if ($view === 'month')
            <div class="overflow-hidden rounded-xl border border-line bg-surface shadow-card">
                <div class="grid grid-cols-7 bg-sidebar text-sidebar-ink">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                        <div class="px-2 py-2 text-[11px] font-medium tracking-[0.06em] uppercase">{{ $weekday }}</div>
                    @endforeach
                </div>
                <div class="grid grid-cols-7 gap-px bg-line">
                    @foreach ($days as $day)
                        @php
                            $dayItems = $itemsByDay->get($day->toDateString(), collect());
                        @endphp
                        <div wire:key="m-{{ $day->toDateString() }}" @class(['grid min-h-28 content-start gap-1 p-1.5', 'bg-surface' => $day->isSameMonth($anchor), 'bg-surface-muted/60' => ! $day->isSameMonth($anchor)])>
                            <button type="button" wire:click="showDay('{{ $day->toDateString() }}')" @class([
                                'grid size-6 place-items-center justify-self-start rounded-full text-xs font-semibold hover:bg-surface-muted',
                                'bg-brand text-white hover:bg-brand' => $day->isToday(),
                                'text-ink' => ! $day->isToday() && $day->isSameMonth($anchor),
                                'text-ink-subtle' => ! $day->isToday() && ! $day->isSameMonth($anchor),
                            ])>{{ $day->day }}</button>
                            @foreach ($dayItems->take(3) as $item)
                                @include('livewire.calendar.partials.item', ['item' => $item, 'compact' => true, 'showOwner' => $showOwner])
                            @endforeach
                            @if ($dayItems->count() > 3)
                                <button type="button" wire:click="showDay('{{ $day->toDateString() }}')" class="justify-self-start px-1 text-[11px] font-semibold text-brand-text hover:underline">+{{ $dayItems->count() - 3 }} more</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif ($view === 'week')
            <div class="grid gap-3 md:grid-cols-7 md:gap-px md:overflow-hidden md:rounded-xl md:border md:border-line md:bg-line md:shadow-card">
                @foreach ($days as $day)
                    @php
                        $dayItems = $itemsByDay->get($day->toDateString(), collect());
                    @endphp
                    <div wire:key="w-{{ $day->toDateString() }}" class="grid min-w-0 content-start gap-1.5 rounded-xl border border-line bg-surface p-2 md:min-h-[26rem] md:rounded-none md:border-0">
                        <button type="button" wire:click="showDay('{{ $day->toDateString() }}')" @class([
                            'flex items-baseline gap-1.5 rounded-md px-1.5 py-1 text-left hover:bg-surface-muted',
                            'bg-brand-soft' => $day->isToday(),
                        ])>
                            <span class="text-[11px] font-medium text-ink-subtle uppercase">{{ $day->format('D') }}</span>
                            <span @class(['text-sm font-bold', 'text-brand-text' => $day->isToday(), 'text-ink' => ! $day->isToday()])>{{ $day->format('j') }}</span>
                            @if ($dayItems->isNotEmpty())<span class="ml-auto text-[11px] text-ink-subtle">{{ $dayItems->count() }}</span>@endif
                        </button>
                        @forelse ($dayItems as $item)
                            @include('livewire.calendar.partials.item', ['item' => $item, 'showOwner' => $showOwner])
                        @empty
                            @if ($sells && ($day->isToday() || $day->isFuture()))
                                <button type="button" x-on:click="$dispatch('open-schedule', { date: '{{ $day->toDateString() }}' })" class="rounded-md border border-dashed border-line px-2 py-2 text-[11px] text-ink-subtle hover:border-brand/40 hover:text-brand-text">+ Schedule</button>
                            @endif
                        @endforelse
                    </div>
                @endforeach
            </div>
        @else
            @php
                $dayItems = $itemsByDay->get($anchor->toDateString(), collect());
            @endphp
            <div class="overflow-hidden rounded-xl border border-line bg-surface shadow-card">
                @forelse ($dayItems as $item)
                    <div wire:key="d-{{ $item->id }}" class="grid grid-cols-[76px_minmax(0,1fr)] gap-3 border-b border-line px-4 py-3 last:border-b-0 sm:grid-cols-[96px_minmax(0,1fr)_auto]">
                        <div class="grid content-start leading-tight">
                            <span class="tabular text-sm font-bold text-ink">{{ $item->has_time ? $item->due_at->format('H:i') : 'Anytime' }}</span>
                            @if ($item->has_time && $item->duration_minutes)<span class="text-xs text-ink-subtle">{{ $item->duration_minutes }} min</span>@endif
                        </div>
                        <div class="grid min-w-0 gap-0.5">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="grid size-6 place-items-center rounded-full bg-brand-soft text-brand-text"><x-ui.icon :name="$item->type->icon()" class="size-3.5" /></span>
                                <span @class(['text-sm font-semibold text-ink', 'line-through text-ink-subtle' => $item->isDone()])>{{ $item->lead->business_name }}</span>
                                <span class="text-[13px] text-ink-muted">{{ $item->type->label() }} — {{ $item->task }}</span>
                                @if ($item->isOverdue())<x-ui.pill tone="danger">Overdue</x-ui.pill>@endif
                                @if ($item->isDone())<x-ui.pill tone="success">Done</x-ui.pill>@endif
                            </span>
                            <span class="text-xs text-ink-subtle">
                                {{ collect([$item->contact_name ? $item->contact_name.($item->contact_role ? ' · '.$item->contact_role : '') : null, $item->location, $showOwner ? $item->user->name : null])->filter()->implode(' · ') ?: '—' }}
                            </span>
                            @if ($item->notes)<span class="text-xs text-ink-muted">{{ $item->notes }}</span>@endif
                        </div>
                        <div class="col-span-2 flex items-center gap-2 sm:col-span-1">
                            @if ($item->user_id === auth()->id())
                                @unless ($item->isDone())
                                    <x-ui.button size="sm" variant="secondary" icon="check" x-on:click="$dispatch('open-schedule', { itemId: {{ $item->id }}, complete: true })">Done</x-ui.button>
                                @endunless
                                <x-ui.button size="sm" variant="ghost" x-on:click="$dispatch('open-schedule', { itemId: {{ $item->id }} })">Edit</x-ui.button>
                            @endif
                            <x-ui.button size="sm" variant="ghost" :href="route('leads.show', $item->lead_id)" wire:navigate>Lead</x-ui.button>
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state icon="clock" title="Nothing scheduled" :description="$sells ? 'Schedule a call, meeting or site visit for this day.' : 'No one has anything scheduled for this day.'" />
                @endforelse
            </div>
        @endif
    </div>
</div>
