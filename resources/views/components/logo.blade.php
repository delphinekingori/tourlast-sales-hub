@props(['inverse' => false])

<span {{ $attributes->merge(['class' => 'inline-flex items-end gap-2']) }}>
    <x-logo-mark @class(['h-6 w-auto', 'text-white' => $inverse, 'text-[#0F5495]' => ! $inverse]) />
    <span @class(['pb-px text-[10px] leading-none font-semibold tracking-[0.1em] whitespace-nowrap uppercase', 'text-sidebar-muted' => $inverse, 'text-brand-text' => ! $inverse])>Sales Hub</span>
</span>
