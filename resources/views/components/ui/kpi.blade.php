@props(['label', 'value', 'tone' => 'text-ink', 'href' => null, 'hint' => null, 'size' => 'md'])

{{--
    One number on a dashboard. A link when $href is given, so the tile opens the
    list behind the number. The label wraps instead of being cut off on phones.
--}}
@php
    $tag = $href ? 'a' : 'div';
    $valueSize = $size === 'lg' ? 'text-2xl sm:text-[1.75rem]' : 'text-lg sm:text-xl';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class(['group grid min-w-0 content-start gap-0.5 bg-surface px-4 py-3', 'transition-colors hover:bg-surface-muted/60 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand' => $href]) }}
>
    <span class="line-clamp-2 text-xs leading-snug font-medium text-ink-subtle">{{ $label }}</span>
    <span class="tabular leading-tight font-bold tracking-tight break-words {{ $valueSize }} {{ $tone }}">{{ $value }}</span>
    @if ($hint)
        <span class="text-xs text-ink-muted">{{ $hint }}</span>
    @endif
</{{ $tag }}>
