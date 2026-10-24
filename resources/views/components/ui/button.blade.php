@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'icon' => null,
])

@php
    $variants = [
        'primary' => 'bg-brand text-brand-ink hover:bg-brand-hover border border-transparent shadow-xs',
        'secondary' => 'bg-surface text-ink border border-line-strong hover:bg-surface-muted hover:border-ink-subtle/40',
        'ghost' => 'text-ink-muted hover:text-ink hover:bg-surface-muted border border-transparent',
        'danger' => 'bg-danger text-white hover:opacity-90 border border-transparent',
        'danger-ghost' => 'text-danger hover:bg-danger-soft border border-transparent',
    ];
    $sizes = [
        // Bigger on touch screens (a finger needs about 40px), compact from the sm breakpoint up.
        'sm' => 'h-9 px-3 text-xs gap-1.5 sm:h-7 sm:px-2.5',
        'md' => 'h-10 px-3.5 text-[13px] gap-1.5 sm:h-8 sm:px-3',
        'lg' => 'h-11 px-4 text-sm gap-2 sm:h-9',
    ];
    $classes = 'inline-flex items-center justify-center rounded-md font-medium whitespace-nowrap transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand disabled:cursor-not-allowed disabled:opacity-50 data-loading:opacity-70 '
        .($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4" />@endif
        {{ $slot }}
    </a>
@else
    {{-- A submit button switches off while its request is running, so a double click cannot send twice. --}}
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes] + ($type === 'submit' ? ['wire:loading.attr' => 'disabled'] : [])) }}>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4" />@endif
        {{ $slot }}
    </button>
@endif
