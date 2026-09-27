@props(['user', 'size' => 'md', 'presence' => false])

@php
    $sizes = ['sm' => 'size-8 text-xs', 'md' => 'size-9 text-[13px]', 'lg' => 'size-12 text-base', 'xl' => 'size-24 text-2xl'];
    $dot = ['sm' => 'size-2.5', 'md' => 'size-2.5', 'lg' => 'size-3', 'xl' => 'size-4'];
    $url = $user->avatarUrl();
@endphp

<span {{ $attributes->merge(['class' => 'relative inline-grid shrink-0']) }}>
    @if ($url)
        <img src="{{ $url }}" alt="" class="{{ $sizes[$size] ?? $sizes['md'] }} rounded-full object-cover">
    @else
        <span class="inline-grid {{ $sizes[$size] ?? $sizes['md'] }} place-items-center rounded-full bg-brand-soft font-bold text-brand-text" aria-hidden="true">{{ $user->initials() }}</span>
    @endif
    @if ($presence)
        <span @class([
            'absolute right-0 bottom-0 rounded-full ring-2 ring-surface',
            $dot[$size] ?? $dot['md'],
            'bg-success' => $user->isOnline(),
            'bg-line-strong' => ! $user->isOnline(),
        ]) title="{{ $user->isOnline() ? 'Online' : 'Offline' }}"></span>
    @endif
</span>
