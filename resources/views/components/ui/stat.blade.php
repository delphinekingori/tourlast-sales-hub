@props(['label', 'value', 'hint' => null])

{{-- Compact KPI tile. --}}
<section {{ $attributes->merge(['class' => 'grid min-w-0 content-start gap-1 rounded-xl border border-line bg-surface px-4 py-3 shadow-card']) }}>
    <p class="truncate text-xs font-medium text-ink-subtle">{{ $label }}</p>
    <p class="tabular truncate text-2xl leading-tight font-bold tracking-tight text-ink">{{ $value }}</p>
    @if ($hint)
        <p class="text-xs text-ink-muted">{{ $hint }}</p>
    @endif
    {{ $slot }}
</section>
