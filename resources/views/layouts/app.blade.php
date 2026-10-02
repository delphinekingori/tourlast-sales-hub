<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth
        <meta name="hub-user-id" content="{{ auth()->id() }}">
    @endauth
    <title>{{ isset($title) ? $title.' · ' : '' }}Tourlast Sales Hub</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/tourlast-icon.svg') }}">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen">
@php
    $user = auth()->user();
    $sections = \App\Support\Navigation::for($user);
    $crumb = \App\Support\Navigation::crumb($sections);
@endphp
<div
    x-data="{
        nav: false,
        collapsed: (() => { try { return localStorage.getItem('tl-sidebar') === '1' } catch (e) { return false } })(),
        toggle() { this.collapsed = ! this.collapsed; try { localStorage.setItem('tl-sidebar', this.collapsed ? '1' : '0') } catch (e) {} },
    }"
    :class="collapsed ? 'lg:grid-cols-[64px_minmax(0,1fr)]' : 'lg:grid-cols-[240px_minmax(0,1fr)]'"
    class="min-h-screen lg:grid lg:grid-cols-[240px_minmax(0,1fr)]"
>
    {{-- Sidebar: drawer below lg, persistent (and collapsible to icons) from lg --}}
    <div x-show="nav" x-cloak x-transition.opacity.duration.150ms class="fixed inset-0 z-40 bg-[#0b1a2e]/40 lg:hidden" x-on:click="nav = false"></div>
    <aside
        :class="[nav ? 'translate-x-0' : '-translate-x-full', collapsed ? 'lg:w-16' : 'lg:w-60']"
        class="fixed inset-y-0 left-0 z-50 flex w-60 -translate-x-full flex-col bg-sidebar text-sidebar-ink transition-[transform,width] duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
    >
        <div :class="collapsed && 'lg:justify-center lg:px-0'" class="flex h-14 shrink-0 items-center justify-between border-b border-white/10 px-4">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center">
                <span :class="collapsed && 'lg:hidden'"><x-logo inverse /></span>
                <img x-cloak :class="collapsed ? 'lg:block' : 'hidden'" src="{{ asset('images/tourlast-icon.svg') }}" alt="Tourlast" class="hidden size-8">
            </a>
            <button type="button" class="rounded-md p-1.5 text-sidebar-muted hover:text-white lg:hidden" x-on:click="nav = false" aria-label="Close menu">
                <x-ui.icon name="x" />
            </button>
        </div>

        <nav class="scrollbar-none flex-1 space-y-4 overflow-y-auto px-2 py-3" aria-label="Main">
            @foreach ($sections as $section)
                <div class="grid gap-px">
                    <p :class="collapsed && 'lg:hidden'" class="px-2.5 pb-1 text-[10.5px] font-medium tracking-[0.08em] text-sidebar-muted/90 uppercase">{{ $section['label'] }}</p>
                    @foreach ($section['items'] as $item)
                        <a
                            href="{{ route($item['route'], $item['params'] ?? []) }}"
                            wire:navigate
                            title="{{ $item['label'] }}"
                            :class="collapsed && 'lg:justify-center lg:px-0'"
                            @class([
                                'relative flex h-8 items-center gap-2.5 rounded-md px-2.5 text-[13px] transition-colors',
                                'bg-white/12 font-medium text-white before:absolute before:inset-y-1.5 before:left-0 before:w-[3px] before:rounded-full before:bg-sky' => $item['active'],
                                'text-sidebar-ink/80 hover:bg-white/6 hover:text-white' => ! $item['active'],
                            ])
                            @if ($item['active']) aria-current="page" @endif
                        >
                            <x-ui.icon :name="$item['icon']" class="size-[17px]" />
                            <span :class="collapsed && 'lg:hidden'" class="truncate">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>

        <div class="hidden border-t border-white/10 p-2 lg:block">
            <button type="button" x-on:click="toggle()" :class="collapsed && 'justify-center'" class="flex h-8 w-full items-center gap-2.5 rounded-md px-2.5 text-[13px] text-sidebar-muted hover:bg-white/6 hover:text-white" :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'">
                <svg :class="collapsed && 'rotate-180'" class="size-[17px] transition-transform" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5" /></svg>
                <span :class="collapsed && 'hidden'">Collapse</span>
            </button>
        </div>
    </aside>

    {{-- Workspace --}}
    <div class="flex min-w-0 flex-col">
        <header class="sticky top-0 z-30 flex h-14 items-center justify-between gap-3 border-b border-line bg-surface px-4 lg:px-6 xl:px-8">
            <div class="flex min-w-0 items-center gap-2">
                <button type="button" class="-ml-1 rounded-md p-1.5 text-ink-muted hover:bg-surface-muted lg:hidden" x-on:click="nav = true" aria-label="Open menu">
                    <x-ui.icon name="menu" />
                </button>
                <nav class="flex min-w-0 items-center gap-1.5 text-[13px]" aria-label="Breadcrumb">
                    @if ($crumb)
                        <span class="hidden text-ink-subtle sm:inline">{{ $crumb['section'] }}</span>
                        <svg class="hidden size-3.5 text-line-strong sm:block" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                        <span class="truncate font-medium text-ink">{{ $title ?? $crumb['page'] }}</span>
                    @else
                        <span class="truncate font-medium text-ink">{{ $title ?? 'Tourlast Sales Hub' }}</span>
                    @endif
                </nav>
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
                <span class="hidden h-7 items-center gap-1.5 rounded-full border border-success/25 bg-success-soft px-2.5 text-xs font-medium text-success md:inline-flex" title="Colleagues can see you are online">
                    <span class="size-1.5 rounded-full bg-success"></span> Online
                </span>
                <livewire:notifications.bell />

                <div class="relative" x-data="{ menu: false }" x-on:click.outside="menu = false" x-on:keydown.escape.window="menu = false">
                    <button type="button" x-on:click="menu = ! menu" class="flex items-center gap-2 rounded-md py-1 pr-1.5 pl-1 hover:bg-surface-muted" aria-haspopup="menu" :aria-expanded="menu">
                        <x-ui.avatar :user="$user" size="sm" />
                        <span class="hidden min-w-0 text-left leading-tight md:grid">
                            <span class="max-w-40 truncate text-[13px] font-medium text-ink">{{ $user->name }}</span>
                            <span class="max-w-40 truncate text-[11px] text-ink-subtle">{{ $user->role()?->label() }}{{ $user->region ? ' · '.$user->region : '' }}</span>
                        </span>
                        <svg class="hidden size-4 text-ink-subtle md:block" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="menu" x-cloak x-transition.opacity.duration.100ms class="absolute right-0 z-50 mt-1.5 w-56 overflow-hidden rounded-lg border border-line bg-surface py-1 shadow-overlay" role="menu">
                        <div class="border-b border-line px-3 py-2">
                            <p class="truncate text-[13px] font-medium text-ink">{{ $user->name }}</p>
                            <p class="truncate text-xs text-ink-subtle">{{ $user->email }}</p>
                        </div>
                        <a href="{{ route('profile') }}" wire:navigate class="flex items-center gap-2 px-3 py-2 text-[13px] text-ink hover:bg-surface-muted" role="menuitem"><x-ui.icon name="user" class="size-4 text-ink-subtle" /> My profile</a>
                        <a href="{{ route('notifications.index') }}" wire:navigate class="flex items-center gap-2 px-3 py-2 text-[13px] text-ink hover:bg-surface-muted" role="menuitem"><x-ui.icon name="mail" class="size-4 text-ink-subtle" /> Notifications</a>
                        <form method="POST" action="{{ route('logout') }}" class="border-t border-line">
                            @csrf
                            <button type="submit" class="flex w-full items-center gap-2 px-3 py-2 text-[13px] text-ink hover:bg-surface-muted" role="menuitem"><x-ui.icon name="logout" class="size-4 text-ink-subtle" /> Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="w-full flex-1 px-4 py-5 lg:px-6 xl:px-8">
            {{ $slot }}
        </main>
    </div>
</div>

@if (auth()->user()->role()?->earnsReferrals())
    <livewire:schedule.editor />
@endif

<x-toasts />
@livewireScripts
</body>
</html>
