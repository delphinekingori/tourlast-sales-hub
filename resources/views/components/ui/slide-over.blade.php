@props(['title', 'description' => null])

{{-- Bind with wire:model="showSomething" on the component tag. --}}
<div
    x-data="{ open: @entangle($attributes->wire('model')) }"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50"
    role="dialog"
    aria-modal="true"
    aria-label="{{ $title }}"
>
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-[#0b1a2e]/40" x-on:click="open = false"></div>

    <div
        x-show="open"
        x-transition:enter="transition duration-150 ease-out"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition duration-150 ease-in"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-surface shadow-overlay"
    >
        <header class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
            <div class="grid gap-1">
                <h2 class="text-base font-bold text-ink">{{ $title }}</h2>
                @if ($description)
                    <p class="text-[13px] text-ink-subtle">{{ $description }}</p>
                @endif
            </div>
            <button type="button" x-on:click="open = false" class="rounded-lg p-1.5 text-ink-subtle hover:bg-surface-muted hover:text-ink" aria-label="Close">
                <x-ui.icon name="x" />
            </button>
        </header>

        <div class="flex-1 overflow-y-auto px-5 py-4">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="flex justify-end gap-2 border-t border-line bg-surface-muted/60 px-5 py-3">
                {{ $footer }}
            </footer>
        @endisset
    </div>
</div>
