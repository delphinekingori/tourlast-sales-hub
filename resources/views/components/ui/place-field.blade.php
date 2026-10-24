@props([
    'label',
    'model',
    'latModel',
    'lngModel',
    'id',
    'placeholder' => null,
    'hint' => null,
])

{{--
    A place box with search suggestions and a "Pick on the map" panel (Google Maps).
    Without GOOGLE_MAPS_API_KEY it is a plain text box. wire:model binds the text;
    latModel and lngModel hold the coordinates of the chosen place.
--}}
@php
    $error = $errors->first($model);
@endphp

<div
    class="grid gap-1"
    x-data="placePicker(@js(['key' => config('services.google_maps.key'), 'country' => config('services.google_maps.country'), 'latPath' => $latModel, 'lngPath' => $lngModel]))"
    x-on:keydown.escape.stop="open = false"
>
    <label for="{{ $id }}" class="text-xs font-medium text-ink-muted">{{ $label }}</label>

    <div class="relative">
        <input
            x-ref="input"
            id="{{ $id }}"
            type="text"
            wire:model="{{ $model }}"
            autocomplete="off"
            role="combobox"
            aria-autocomplete="list"
            aria-controls="{{ $id }}-list"
            x-bind:aria-expanded="open && suggestions.length > 0"
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            class="h-9 w-full rounded-md border bg-surface px-3 text-[13px] text-ink placeholder:text-ink-subtle shadow-xs transition focus:outline-none focus:ring-3 {{ $error ? 'border-danger focus:ring-danger-soft' : 'border-line-strong focus:border-brand focus:ring-brand-soft' }}"
        />

        <ul
            x-show="open && suggestions.length > 0"
            x-cloak
            id="{{ $id }}-list"
            role="listbox"
            class="absolute top-full z-20 mt-1 max-h-60 w-full overflow-auto rounded-md border border-line bg-surface py-1 shadow-overlay"
        >
            <template x-for="(item, index) in suggestions" x-bind:key="item.id">
                <li
                    role="option"
                    x-bind:aria-selected="index === active"
                    x-on:mousedown.prevent="choose(item)"
                    x-on:mouseenter="active = index"
                    x-bind:class="index === active ? 'bg-brand-soft' : ''"
                    class="grid cursor-pointer gap-0.5 px-3 py-2 text-[13px] leading-tight"
                >
                    <span class="font-semibold text-ink" x-text="item.main"></span>
                    <span class="text-xs text-ink-subtle" x-text="item.secondary"></span>
                </li>
            </template>
        </ul>
    </div>

    <button
        type="button"
        x-show="enabled"
        x-cloak
        x-on:click="toggleMap()"
        class="inline-flex min-h-8 items-center gap-1.5 justify-self-start rounded-md text-[13px] font-semibold text-brand-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
    >
        <x-ui.icon name="map" class="size-4" />
        <span x-text="mapOpen ? 'Hide the map' : 'Pick on the map'"></span>
    </button>

    <div x-show="mapOpen" x-cloak class="grid gap-1">
        <div x-ref="map" class="h-56 w-full rounded-lg border border-line bg-surface-muted" wire:ignore></div>
        <p class="text-xs text-ink-subtle">Tap the map, or drag the pin. The place name fills in for you.</p>
    </div>

    <p x-show="status" x-cloak x-text="status" class="text-xs text-ink-subtle" aria-live="polite"></p>

    @if ($error)
        <p id="{{ $id }}-error" class="text-xs text-danger">{{ $error }}</p>
    @elseif ($hint)
        <p class="text-xs text-ink-subtle">{{ $hint }}</p>
    @endif
</div>
