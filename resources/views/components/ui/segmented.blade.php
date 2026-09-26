@props(['options'])

{{-- A compact option switcher bound with wire:model.live="property". --}}
@php($model = $attributes->wire('model')->value())

<div class="inline-flex rounded-md border border-line bg-surface p-0.5" role="radiogroup">
    @foreach ($options as $value => $label)
        <label wire:key="seg-{{ $model }}-{{ $value }}" class="cursor-pointer">
            <input type="radio" value="{{ $value }}" {{ $attributes->whereStartsWith('wire:model') }} class="peer sr-only">
            <span class="block rounded px-2.5 py-1 text-xs font-medium text-ink-subtle transition-colors peer-checked:bg-brand-soft peer-checked:text-brand-text peer-focus-visible:outline-2 peer-focus-visible:outline-brand hover:text-ink">{{ $label }}</span>
        </label>
    @endforeach
</div>
