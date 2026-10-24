@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'hint' => null,
    'optional' => false,
])

@php
    $field = $name ?? $attributes->whereStartsWith('wire:model')->first();
    $id = $attributes->get('id') ?? 'field-'.str_replace('.', '-', (string) $field);
    $error = $field ? $errors->first($field) : null;
@endphp

<div class="grid gap-1">
    @if ($label)
        <label for="{{ $id }}" class="text-[13px] font-medium text-ink">{{ $label }}@if ($optional) <span class="font-normal text-ink-subtle">(optional)</span>@endif</label>
    @endif

    <input
        id="{{ $id }}"
        type="{{ $type }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->except('id')->merge([
            'class' => 'h-10 w-full rounded-md border bg-surface px-3 text-sm text-ink sm:h-9 sm:text-[13px] placeholder:text-ink-subtle shadow-xs transition focus:outline-none focus:ring-3 '
                .($error ? 'border-danger focus:ring-danger-soft' : 'border-line-strong focus:border-brand focus:ring-brand-soft'),
        ]) }}
    />

    @if ($error)
        <p id="{{ $id }}-error" class="text-[13px] text-danger">{{ $error }}</p>
    @elseif ($hint)
        <p class="text-[13px] text-ink-subtle">{{ $hint }}</p>
    @endif
</div>
