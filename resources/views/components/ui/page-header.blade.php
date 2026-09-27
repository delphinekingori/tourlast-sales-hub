@props(['title', 'description' => null, 'eyebrow' => null])

{{-- The top-bar breadcrumb already shows the section, so the eyebrow is not repeated here. --}}
<div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
    <div class="grid min-w-0 gap-1">
        <h1 class="text-2xl leading-tight font-bold tracking-tight text-ink">{{ $title }}</h1>
        @if ($description)
            <p class="max-w-3xl text-sm text-ink-muted">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
