<div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" wire:poll.visible.{{ $pollSeconds }}s>
    <button type="button" x-on:click="open = ! open" class="relative rounded-lg p-2 text-ink-muted hover:bg-surface-muted hover:text-ink" aria-label="Notifications{{ $unread ? ', '.$unread.' unread' : '' }}">
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>
        @if ($unread)
            <span class="absolute -top-0.5 -right-0.5 grid min-w-[18px] place-items-center rounded-full bg-danger px-1 text-[10px] leading-[18px] font-bold text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.origin.top.right class="absolute right-0 z-50 mt-2 w-[min(380px,calc(100vw-2rem))] overflow-hidden rounded-xl border border-line bg-surface shadow-overlay">
        <div class="flex items-center justify-between border-b border-line px-4 py-3">
            <p class="text-sm font-bold text-ink">Notifications</p>
            @if ($unread)
                <button type="button" wire:click="markAllRead" class="text-[13px] font-semibold text-brand-text hover:underline">Mark all read</button>
            @endif
        </div>
        <div class="max-h-[420px] overflow-y-auto">
            @forelse ($items as $item)
                @php
                    $meta = \App\Notifications\SmartAlert::Types[$item['type']] ?? ['icon' => 'mail', 'tone' => 'brand'];
                @endphp
                <button type="button" wire:key="bell-{{ $item['key'] }}" wire:click="open('{{ $item['key'] }}')" class="flex w-full items-start gap-3 border-b border-line px-4 py-3 text-left last:border-b-0 hover:bg-surface-muted/60">
                    <span @class(['mt-0.5 grid size-8 shrink-0 place-items-center rounded-full', 'bg-brand-soft text-brand-text' => $item['kind'] === 'announcement', 'bg-surface-muted text-ink-muted' => $item['kind'] === 'alert'])>
                        <x-ui.icon :name="$item['kind'] === 'announcement' ? 'mail' : $meta['icon']" class="size-4" />
                    </span>
                    <span class="grid min-w-0 flex-1 gap-0.5 leading-tight">
                        <span class="flex items-center gap-2">
                            <span @class(['truncate text-[13px]', 'font-bold text-ink' => $item['unread'], 'font-semibold text-ink-muted' => ! $item['unread']])>{{ $item['title'] }}</span>
                            @if ($item['importance'] === 'important')<x-ui.pill tone="danger" :dot="false" class="px-1.5 text-[10px]">Important</x-ui.pill>@endif
                        </span>
                        <span class="line-clamp-2 text-xs text-ink-subtle">{{ $item['body'] }}</span>
                        <span class="text-[11px] text-ink-subtle">{{ $item['kind'] === 'announcement' ? 'From '.$item['author']?->name.' · ' : '' }}{{ $item['at']->diffForHumans() }}</span>
                    </span>
                    @if ($item['unread'])<span class="mt-1.5 size-2 shrink-0 rounded-full bg-brand"></span>@endif
                </button>
            @empty
                <p class="px-4 py-8 text-center text-[13px] text-ink-subtle">You're all caught up.</p>
            @endforelse
        </div>
        <a href="{{ route('notifications.index') }}" wire:navigate class="block border-t border-line bg-surface-muted/60 px-4 py-2.5 text-center text-[13px] font-semibold text-brand-text hover:underline">See all notifications</a>
    </div>
</div>
