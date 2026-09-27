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
        'sm' => 'h-7 px-2.5 text-xs gap-1.5',
        'md' => 'h-8 px-3 text-[13px] gap-1.5',
        'lg' => 'h-9 px-4 text-sm gap-2',
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
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4" />@endif
        {{ $slot }}
    </button>
@endif
