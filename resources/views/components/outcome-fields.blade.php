@props([
    'model',
    'objection' => null,
    'required' => true,
    'notes' => 'notes',
    'id' => 'outcome',
])

{{-- Why the property said no, and when to try again. $model is the Livewire array, e.g. "lost". --}}
@php
    $needsCompetitor = in_array($objection, \App\Enums\Objection::valuesNeedingCompetitor(), true);
@endphp
<div {{ $attributes->merge(['class' => 'grid gap-3']) }}>
    <x-ui.select :label="'Primary objection'.($required ? ' *' : '')" wire:model.live="{{ $model }}.objection" id="{{ $id }}-objection">
        <option value="">Choose…</option>
        @foreach (\App\Enums\Objection::cases() as $option)
            <option value="{{ $option->value }}">{{ $option->label() }}</option>
        @endforeach
    </x-ui.select>
    <x-ui.input :label="'Competitor'.($needsCompetitor ? ' *' : '')" wire:model="{{ $model }}.competitor" id="{{ $id }}-competitor" list="{{ $id }}-competitors" :placeholder="$needsCompetitor ? 'e.g. Booking.com' : 'Optional'" />
    <datalist id="{{ $id }}-competitors">
        @foreach (config('hub.competitors') as $competitor)
            <option value="{{ $competitor }}"></option>
        @endforeach
    </datalist>
    <div class="grid gap-1">
        <label for="{{ $id }}-notes" class="text-xs font-medium text-ink-muted">Notes</label>
        <textarea id="{{ $id }}-notes" wire:model="{{ $model }}.{{ $notes }}" rows="2" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink placeholder:text-ink-subtle focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none" placeholder="e.g. Management renewed their contract for another year."></textarea>
        @error($model.'.'.$notes)<p class="text-xs text-danger">{{ $message }}</p>@enderror
    </div>
    <x-ui.input label="Re-engage on" type="date" wire:model="{{ $model }}.reengage_on" id="{{ $id }}-reengage" min="{{ now()->addDay()->toDateString() }}" hint="Optional. A follow-up is scheduled for this date so the property isn't forgotten." />
</div>
