@props(['title', 'description' => null, 'maxWidth' => 'max-w-md'])

{{-- A centred pop-up. Bind with wire:model="showSomething" on the component tag. --}}
<div
    x-data="{ open: @entangle($attributes->wire('model')) }"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 grid place-items-center overflow-y-auto p-4"
    role="dialog"
    aria-modal="true"
    aria-label="{{ $title }}"
>
    <div x-show="open" x-transition.opacity class="fixed inset-0 bg-[#0b1a2e]/45" x-on:click="open = false"></div>

    <div
        x-show="open"
        x-transition:enter="transition duration-150 ease-out"
        x-transition:enter-start="scale-95 opacity-0"
        x-transition:enter-end="scale-100 opacity-100"
        class="relative w-full {{ $maxWidth }} rounded-xl bg-surface shadow-overlay"
    >
        <header class="flex items-start justify-between gap-4 px-6 pt-5 pb-3">
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

        <div class="px-6 pb-5">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="flex justify-end gap-2 rounded-b-xl border-t border-line bg-surface-muted/60 px-6 py-4">
                {{ $footer }}
            </footer>
        @endisset
    </div>
</div>
