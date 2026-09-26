@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'hint' => null,
])

@php
    $field = $name ?? $attributes->whereStartsWith('wire:model')->first();
    $id = $attributes->get('id') ?? 'field-'.str_replace('.', '-', (string) $field);
    $error = $field ? $errors->first($field) : null;
@endphp

<div class="grid gap-1">
    @if ($label)
        <label for="{{ $id }}" class="text-xs font-medium text-ink-muted">{{ $label }}</label>
    @endif

    <input
        id="{{ $id }}"
        type="{{ $type }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->except('id')->merge([
            'class' => 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] text-ink placeholder:text-ink-subtle shadow-xs transition focus:outline-none focus:ring-3 '
                .($error ? 'border-danger focus:ring-danger-soft' : 'border-line-strong focus:border-brand focus:ring-brand-soft'),
        ]) }}
    />

    @if ($error)
        <p id="{{ $id }}-error" class="text-xs text-danger">{{ $error }}</p>
    @elseif ($hint)
        <p class="text-xs text-ink-subtle">{{ $hint }}</p>
    @endif
</div>
