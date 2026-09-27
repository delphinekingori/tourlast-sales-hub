@props(['placeholder' => 'Search', 'wide' => false])

<label @class(['relative w-full', 'sm:w-72' => ! $wide, $attributes->get('class')])>
    <span class="sr-only">{{ $placeholder }}</span>
    <x-ui.icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-subtle" />
    <input
        type="search"
        placeholder="{{ $placeholder }}"
        {{ $attributes->except('class')->merge(['class' => 'h-9 w-full rounded-md border border-line-strong bg-surface pr-3 pl-9 text-[13px] text-ink placeholder:text-ink-subtle focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none']) }}
    >
</label>
