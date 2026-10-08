{{-- Record / update incident pop-up (RecordsIncidents). Needs $incidentProviders, $incidentPackages, $travelUsers. --}}
@php
    $textarea = 'w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink placeholder:text-ink-subtle focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none';
@endphp

<x-ui.modal wire:model="showIncident" :title="$editingIncidentId ? 'Update incident' : 'Record incident'" description="Serious provider issues: no-shows, safety concerns, complaints, cancellations." maxWidth="max-w-xl">
    <form wire:submit="saveIncident" id="incident-form" class="grid gap-3 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <x-ui.select label="Provider *" wire:model.live="incidentForm.travel_provider_id" id="incident-provider" :disabled="(bool) $editingIncidentId">
                <option value="">Choose a provider</option>
                @foreach ($incidentProviders as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        @if ($incidentPackages->isNotEmpty())
            <div class="sm:col-span-2">
                <x-ui.select label="Package" wire:model="incidentForm.package_id" id="incident-package">
                    <option value="">Not about a specific package</option>
                    @foreach ($incidentPackages as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>
        @endif
        <x-ui.input label="Date *" type="date" wire:model="incidentForm.occurred_on" id="incident-date" />
        <x-ui.select label="Issue type *" wire:model="incidentForm.type" id="incident-type">
            @foreach (\App\Enums\Travel\IncidentType::cases() as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select label="Severity *" wire:model="incidentForm.severity" id="incident-severity">
            @foreach (\App\Enums\Travel\IncidentSeverity::cases() as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select label="Status *" wire:model.live="incidentForm.status" id="incident-status">
            @foreach (\App\Enums\Travel\IncidentStatus::cases() as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </x-ui.select>
        <div class="sm:col-span-2">
            <x-ui.select label="Assigned to" wire:model="incidentForm.assigned_to" id="incident-assignee">
                <option value="">Nobody yet</option>
                @foreach ($travelUsers as $person)
                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="grid gap-1 sm:col-span-2">
            <label for="incident-description" class="text-xs font-medium text-ink-muted">What happened *</label>
            <textarea id="incident-description" rows="3" wire:model="incidentForm.description" class="{{ $textarea }}"></textarea>
            @error('incidentForm.description')<p class="text-xs text-danger">{{ $message }}</p>@enderror
        </div>
        <div class="grid gap-1 sm:col-span-2">
            <label for="incident-resolution" class="text-xs font-medium text-ink-muted">Resolution</label>
            <textarea id="incident-resolution" rows="2" wire:model="incidentForm.resolution" class="{{ $textarea }}" placeholder="Required when resolved or closed"></textarea>
            @error('incidentForm.resolution')<p class="text-xs text-danger">{{ $message }}</p>@enderror
        </div>
    </form>

    <x-slot:footer>
        <x-ui.button variant="ghost" x-on:click="open = false">Cancel</x-ui.button>
        <x-ui.button type="submit" form="incident-form">{{ $editingIncidentId ? 'Save' : 'Record incident' }}</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
