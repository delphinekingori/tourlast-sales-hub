{{--
    One row per traveler. $model is the Livewire path of the rows array
    ("form.guests" or "guests"); $rows is that array; $idPrefix keeps ids unique.
--}}
@php
    $typeLabels = \App\Enums\Travel\GuestType::options();
    $counters = [];
@endphp

<div class="grid gap-3">
    @error($model)
        <div class="rounded-lg border border-danger/40 bg-danger-soft/50 px-3 py-2 text-[13px] text-danger">{{ $message }}</div>
    @enderror

    @foreach ($rows as $i => $guest)
        @php
            $type = $guest['type'] ?? 'adult';
            $counters[$type] = ($counters[$type] ?? 0) + 1;
            $id = $idPrefix.'-'.$i;
        @endphp
        <fieldset class="grid gap-3 rounded-lg border border-line px-3 pt-2 pb-3" wire:key="{{ $id }}">
            <legend class="flex items-center gap-2 px-1 text-xs font-semibold text-ink">
                Guest {{ $i + 1 }}
                <span class="font-normal text-ink-subtle">· {{ $typeLabels[$type] ?? ucfirst($type) }} {{ $counters[$type] }}</span>
                @if (! empty($guest['is_booker']))<x-ui.pill tone="brand" :dot="false">Booker</x-ui.pill>@endif
            </legend>
            <div class="grid gap-3 md:grid-cols-3">
                <x-ui.input label="Full name (as on ID / passport)" wire:model="{{ $model }}.{{ $i }}.full_name" id="{{ $id }}-name" required />
                <x-ui.input label="Nationality" wire:model="{{ $model }}.{{ $i }}.nationality" id="{{ $id }}-nationality" />
                <x-ui.input label="ID / passport number" wire:model="{{ $model }}.{{ $i }}.id_number" id="{{ $id }}-id-number" autocomplete="off" />
                <x-ui.input label="Date of birth" type="date" wire:model="{{ $model }}.{{ $i }}.date_of_birth" id="{{ $id }}-dob" max="{{ now()->toDateString() }}" />
                <x-ui.input label="Phone" wire:model="{{ $model }}.{{ $i }}.phone" id="{{ $id }}-phone" />
                <x-ui.input label="Email" type="email" wire:model="{{ $model }}.{{ $i }}.email" id="{{ $id }}-email" />
            </div>
            <x-ui.input label="Dietary / medical needs" wire:model="{{ $model }}.{{ $i }}.special_requirements" id="{{ $id }}-needs" placeholder="e.g. vegetarian, nut allergy, uses a wheelchair" />
        </fieldset>
    @endforeach
</div>
