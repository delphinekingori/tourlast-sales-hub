@props(['user'])

@if ($user->isOnline())
    <x-ui.pill tone="success" {{ $attributes }}>Online</x-ui.pill>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-xs text-ink-subtle']) }}>
        <span class="size-1.5 rounded-full bg-line-strong"></span>
        {{ $user->last_seen_at ? 'Seen '.$user->last_seen_at->diffForHumans() : ($user->last_login_at ? 'Seen '.$user->last_login_at->diffForHumans() : 'Offline') }}
    </span>
@endif
