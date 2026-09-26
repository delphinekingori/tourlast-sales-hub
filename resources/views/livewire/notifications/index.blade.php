<div class="grid gap-5">
    <x-ui.page-header
        eyebrow="Inbox"
        title="Notifications"
        :description="$canPublish ? 'Announcements to the team and Smart Alerts about partners, deals, follow-ups and contracts.' : 'Announcements from management, HR and Finance, and alerts about your own partners and follow-ups.'"
    >
        <x-slot:actions>
            @if ($unread)
                <x-ui.button variant="secondary" icon="check" wire:click="markAllRead">Mark all read</x-ui.button>
            @endif
            @if ($canPublish)
                <x-ui.button icon="plus" wire:click="openCompose">New announcement</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="inline-flex flex-wrap justify-self-start rounded-md border border-line bg-surface p-0.5">
        @foreach (['all' => 'All', 'announcements' => 'Announcements', 'alerts' => 'Smart Alerts'] as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $key }}')" wire:key="nf-{{ $key }}"
                @class(['rounded px-2.5 py-1 text-xs font-medium', 'bg-brand-soft text-brand-text' => $filter === $key, 'text-ink-subtle hover:text-ink' => $filter !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-xl border border-line bg-surface shadow-card">
        @forelse ($items as $item)
            @php
                $meta = \App\Notifications\SmartAlert::Types[$item['type']] ?? ['icon' => 'mail', 'tone' => 'brand', 'label' => 'Announcement'];
            @endphp
            <div wire:key="nf-{{ $item['key'] }}" @class(['flex items-start gap-4 border-b border-line px-4 py-3 last:border-b-0', 'bg-brand-soft/25' => $item['unread']])>
                @if ($item['kind'] === 'announcement' && $item['author'])
                    <x-ui.avatar :user="$item['author']" />
                @else
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-surface-muted text-ink-muted"><x-ui.icon :name="$meta['icon']" class="size-4" /></span>
                @endif
                <div class="grid min-w-0 flex-1 gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p @class(['text-sm', 'font-bold text-ink' => $item['unread'], 'font-semibold text-ink' => ! $item['unread']])>{{ $item['title'] }}</p>
                        @if ($item['kind'] === 'announcement')
                            <x-ui.pill :tone="$item['importance'] === 'important' ? 'danger' : 'brand'" :dot="false">{{ $item['importance'] === 'important' ? 'Important' : 'Announcement' }}</x-ui.pill>
                        @else
                            <x-ui.pill :tone="$meta['tone']" :dot="false">{{ $meta['label'] }}</x-ui.pill>
                        @endif
                    </div>
                    <p class="text-sm whitespace-pre-line text-ink-muted">{{ $item['body'] }}</p>
                    <p class="text-xs text-ink-subtle">
                        @if ($item['kind'] === 'announcement') {{ $item['author']?->name }} · to {{ $item['audience'] }} · @endif
                        {{ $item['at']->format('j M Y, H:i') }} ({{ $item['at']->diffForHumans() }})
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    @if ($item['url'])
                        <x-ui.button size="sm" variant="secondary" wire:click="open('{{ $item['key'] }}')">Open</x-ui.button>
                    @elseif ($item['unread'])
                        <x-ui.button size="sm" variant="ghost" wire:click="open('{{ $item['key'] }}')">Mark read</x-ui.button>
                    @endif
                    @if ($item['kind'] === 'announcement' && ($item['author']?->is(auth()->user()) || $canModerate))
                        <x-ui.button size="sm" variant="danger-ghost" wire:click="delete({{ $item['id'] }})" wire:confirm="Remove this announcement for everyone?">Remove</x-ui.button>
                    @endif
                </div>
            </div>
        @empty
            <x-ui.empty-state icon="mail" title="Nothing here yet" description="Announcements and alerts will appear here." />
        @endforelse
    </div>

    @if ($canPublish)
        <x-ui.slide-over wire:model="showCompose" title="New announcement" description="Salespeople can read announcements but not write them.">
            <form id="compose-form" wire:submit="publish" class="grid gap-4">
                <x-ui.input label="Title" wire:model="compose.title" id="compose-title" placeholder="e.g. September payouts sent" />
                <div class="grid gap-1.5">
                    <label for="compose-body" class="text-xs font-medium text-ink-muted">Message</label>
                    <textarea id="compose-body" wire:model="compose.body" rows="6" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none"></textarea>
                    @error('compose.body')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                </div>
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-xs font-medium text-ink-muted">Who should see it?</legend>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($audiences as $key => $group)
                            <label wire:key="aud-{{ $key }}" class="cursor-pointer">
                                <input type="checkbox" wire:model="compose.audience" value="{{ $key }}" class="peer sr-only">
                                <span class="inline-flex rounded-full border border-line-strong px-3 py-1.5 text-[13px] font-semibold text-ink-muted peer-checked:border-brand peer-checked:bg-brand-soft peer-checked:text-brand-text peer-focus-visible:outline-2 peer-focus-visible:outline-brand">{{ $group['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('compose.audience')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
                </fieldset>
                <label class="flex items-center gap-2.5 text-sm text-ink">
                    <input type="checkbox" wire:model="compose.important" id="compose-important" class="size-4 accent-[var(--tl-brand)]">
                    Mark as important
                </label>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                <x-ui.button type="submit" form="compose-form">Publish</x-ui.button>
            </x-slot:footer>
        </x-ui.slide-over>
    @endif
</div>
