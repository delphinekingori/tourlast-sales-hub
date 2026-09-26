@props(['icon' => 'clipboard', 'title', 'description' => null])

<div {{ $attributes->merge(['class' => 'grid justify-items-center gap-2.5 px-6 py-8 text-center']) }}>
    <span class="grid size-9 place-items-center rounded-full bg-brand-soft text-brand-text">
        <x-ui.icon :name="$icon" />
    </span>
    <div class="grid gap-1">
        <p class="text-sm font-semibold text-ink">{{ $title }}</p>
        @if ($description)
            <p class="max-w-sm text-[13px] text-ink-subtle">{{ $description }}</p>
        @endif
    </div>
    {{ $slot }}
</div>
