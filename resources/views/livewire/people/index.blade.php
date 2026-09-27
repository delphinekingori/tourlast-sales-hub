<div class="grid gap-5" wire:poll.60s>
    <x-ui.page-header eyebrow="Team" title="People" :description="$onlineCount.' of the team online now. Online means active in the Hub within the last five minutes.'" />

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="role" id="people-role" aria-label="Role" class="h-9 rounded-md border border-line-strong bg-surface px-3 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none">
                <option value="">All roles</option>
                @foreach ($roles as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm text-ink-muted">
                <input type="checkbox" wire:model.live="onlineOnly" id="people-online" class="size-4 accent-[var(--tl-brand)]"> Online only
            </label>
        </div>
        <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Search name, position or region" />
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($people as $person)
            <a wire:key="person-{{ $person->id }}" href="{{ route('people.show', $person) }}" wire:navigate class="flex items-start gap-4 rounded-xl border border-line bg-surface p-5 shadow-card transition hover:border-brand/40">
                <x-ui.avatar :user="$person" size="lg" presence />
                <div class="grid min-w-0 gap-1">
                    <p class="truncate font-bold text-ink">{{ $person->name }}</p>
                    <p class="truncate text-[13px] text-ink-muted">{{ $person->job_title ?? $person->role()?->label() }}</p>
                    <p class="truncate text-xs text-ink-subtle">{{ $person->role()?->label() }}{{ $person->region ? ' · '.$person->region : '' }}</p>
                    <div class="mt-1"><x-ui.presence :user="$person" /></div>
                </div>
            </a>
        @empty
            <x-ui.card class="sm:col-span-2 xl:col-span-3"><x-ui.empty-state icon="users" title="Nobody matches" /></x-ui.card>
        @endforelse
    </div>
</div>
