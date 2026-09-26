<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}Tourlast Sales Hub</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/tourlast-icon.svg') }}">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen">
<div class="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">

    {{-- Brand panel --}}
    <aside class="relative hidden overflow-hidden bg-navy text-white lg:flex lg:flex-col lg:justify-between lg:p-12">
        <div class="pointer-events-none absolute -right-24 -bottom-24 size-[420px] rounded-full border-[56px] border-sky/15"></div>
        <div class="pointer-events-none absolute top-1/3 -right-10 size-28 bg-sky/25"></div>

        <x-logo inverse />

        <div class="relative grid max-w-md gap-5">
            <h1 class="text-[34px] leading-tight font-extrabold tracking-tight">Every partner you onboard, credited to you.</h1>
            <p class="text-[15px] leading-relaxed text-white/75">Share your referral link, guide providers onto tourlast.com, and watch your progress update as they are approved.</p>
            <dl class="mt-2 grid grid-cols-3 gap-4 border-t border-white/15 pt-6 text-sm">
                <div class="grid gap-1"><dt class="text-white/60">Share</dt><dd class="font-semibold">Your link</dd></div>
                <div class="grid gap-1"><dt class="text-white/60">Track</dt><dd class="font-semibold">Signups</dd></div>
                <div class="grid gap-1"><dt class="text-white/60">Hit</dt><dd class="font-semibold">Your target</dd></div>
            </dl>
        </div>

        <p class="relative text-xs text-white/50">Private system for the Tourlast team. Access is by invitation only.</p>
    </aside>

    {{-- Form --}}
    <main class="flex flex-col justify-center px-4 py-10 sm:px-10">
        <div class="mx-auto w-full max-w-[400px]">
            <div class="mb-8 lg:hidden"><x-logo /></div>
            {{ $slot }}
        </div>
    </main>
</div>
@livewireScripts
</body>
</html>
