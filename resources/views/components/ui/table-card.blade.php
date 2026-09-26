@props(['paginator' => null, 'sticky' => true])

{{--
    Enterprise table shell: sticky header, compact rows, subtle borders and a
    dimmed state while Livewire is loading. Cell padding is normalised here so
    every table in the Hub looks the same.
--}}
<div {{ $attributes->merge(['class' => 'min-w-0 overflow-hidden rounded-xl border border-line bg-surface shadow-card']) }}>
    <div
        wire:loading.delay.class="opacity-60"
        @class([
            'overflow-x-auto transition-opacity',
            'max-h-[calc(100dvh-15rem)] min-h-40 overflow-y-auto' => $sticky,
            '[&_thead_th]:sticky [&_thead_th]:top-0 [&_thead_th]:z-10 [&_thead_th]:bg-sidebar [&_thead_th]:text-sidebar-ink [&_thead_th]:px-4 [&_thead_th]:py-2 [&_thead_th]:text-[11px] [&_thead_th]:font-medium [&_thead_th]:tracking-[0.06em] [&_thead_th]:whitespace-nowrap',
            '[&_tbody_td]:px-4 [&_tbody_td]:py-2.5 [&_tbody_td]:align-middle [&_tbody_tr]:transition-colors [&_tbody_tr:hover]:bg-surface-muted/50',
        ])
    >
        {{ $slot }}
    </div>
    @if ($paginator?->hasPages())
        <div class="border-t border-line px-4 py-2.5">{{ $paginator->links() }}</div>
    @endif
</div>
