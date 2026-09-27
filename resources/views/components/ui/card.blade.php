@props(['title' => null, 'description' => null, 'padding' => true])

<section {{ $attributes->merge(['class' => 'min-w-0 rounded-xl border border-line bg-surface shadow-card']) }}>
    @if ($title || isset($actions))
        <header class="flex min-h-12 flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-line px-4 py-2.5">
            <div class="grid min-w-0 gap-0.5">
                <h2 class="text-sm font-semibold text-ink">{{ $title }}</h2>
                @if ($description)
                    <p class="text-xs text-ink-subtle">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div @class(['p-4' => $padding])>
        {{ $slot }}
    </div>
</section>
