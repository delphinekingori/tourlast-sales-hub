@php
    $sold = $departure->soldSlots();
    $url = \Illuminate\Support\Facades\Route::has('travel.departures.index') ? route('travel.departures.index', ['package' => $departure->package_id]) : null;
    $tag = $url ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($url) href="{{ $url }}" wire:navigate @endif wire:key="dep-{{ $departure->id }}"
    class="grid w-full min-w-0 gap-0.5 rounded-md border border-success/30 bg-success-soft/50 px-2 py-1.5 text-left transition-colors hover:border-success/60"
    title="Departure · {{ $departure->package?->name }} · {{ $sold }}/{{ $departure->capacity }} sold">
    <span class="flex min-w-0 items-center gap-1.5 text-[11px] leading-tight">
        <x-ui.icon name="map" class="size-3.5 shrink-0 text-success" />
        <span class="tabular shrink-0 font-semibold text-ink">{{ $departure->start_time ? \Illuminate\Support\Str::substr($departure->start_time, 0, 5) : 'Departure' }}</span>
        <span class="tabular ml-auto shrink-0 text-ink-subtle">{{ $sold }}/{{ $departure->capacity }}</span>
    </span>
    <span class="truncate text-xs font-medium text-ink">{{ $departure->package?->name }}</span>
    @unless ($compact ?? false)
        <span class="truncate text-[11px] text-ink-muted">Departure · until {{ $departure->ends_on->format('D j M') }}</span>
    @endunless
</{{ $tag }}>
