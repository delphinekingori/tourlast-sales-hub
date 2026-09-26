@props(['tone' => 'neutral', 'dot' => true])

@php
    $tones = [
        'neutral' => 'bg-surface-muted text-ink-muted',
        'brand' => 'bg-brand-soft text-brand-text',
        'success' => 'bg-success-soft text-success',
        'warning' => 'bg-warning-soft text-warning',
        'danger' => 'bg-danger-soft text-danger',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-px text-[11.5px] leading-5 font-medium '.($tones[$tone] ?? $tones['neutral'])]) }}>
    @if ($dot)<span class="size-1.5 rounded-full bg-current"></span>@endif
    {{ $slot }}
</span>
