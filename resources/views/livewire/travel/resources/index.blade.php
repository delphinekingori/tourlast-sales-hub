@php($isDrivers = $tab === 'drivers')

<div class="grid gap-5">
    <x-ui.page-header title="Drivers & guides" description="Who can be assigned to trips. A driver or guide already on an overlapping trip can't be assigned again unless a Sales Admin overrides the clash.">
        <x-slot:actions>
            <x-ui.button icon="plus" wire:click="create">{{ $isDrivers ? 'Add driver' : 'Add guide' }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-wrap items-center gap-3">
        <x-ui.segmented wire:model.live="tab" :options="['drivers' => 'Drivers ('.$counts['drivers'].')', 'guides' => 'Guides ('.$counts['guides'].')']" />
        <x-ui.search wire:model.live.debounce.300ms="search" :placeholder="$isDrivers ? 'Name, phone or registration' : 'Name or phone'" />
        <div class="w-44">
            <x-ui.select wire:model.live="status" id="res-status" aria-label="Status">
                <option value="">Any status</option>
                @foreach (\App\Enums\Travel\ResourceStatus::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
            </x-ui.select>
        </div>
    </div>

    <x-ui.table-card :paginator="$resources">
        <table class="w-full min-w-[960px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    @if ($isDrivers)
                        <th>Vehicle</th>
                        <th>Registration</th>
                        <th>Licence</th>
                    @else
                        <th>Languages</th>
                        <th>Specialization</th>
                    @endif
                    <th>Provider</th>
                    <th>Status</th>
                    <th>Upcoming trips</th>
                    <th class="text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($resources as $resource)
                    <tr wire:key="res-{{ $tab }}-{{ $resource->id }}">
                        <td class="font-medium text-ink">{{ $resource->name }}</td>
                        <td class="text-ink-muted">{{ $resource->phone ?? '—' }}</td>
                        @if ($isDrivers)
                            <td class="text-ink-muted">{{ $resource->vehicle ?? '—' }}</td>
                            <td class="text-ink-muted">{{ $resource->vehicle_registration ?? '—' }}</td>
                            <td class="text-ink-muted">{{ $resource->license_number ?? '—' }}</td>
                        @else
                            <td class="text-ink-muted">{{ implode(', ', $resource->languages ?? []) ?: '—' }}</td>
                            <td class="text-ink-muted">{{ $resource->specialization ?? '—' }}</td>
                        @endif
                        <td class="text-ink-muted">{{ $resource->provider?->name ?? 'Independent' }}</td>
                        <td><x-ui.pill :tone="$resource->status->tone()">{{ $resource->status->label() }}</x-ui.pill></td>
                        <td class="text-[13px]">
                            @forelse ($upcoming[$resource->id] ?? [] as $trip)
                                <span class="block whitespace-nowrap text-ink-muted">{{ $trip->starts_on->format('M j') }} — {{ $trip->package?->name }}</span>
                            @empty
                                <span class="text-ink-subtle">None</span>
                            @endforelse
                        </td>
                        <td class="text-right"><x-ui.button variant="ghost" size="sm" wire:click="edit({{ $resource->id }})">Edit</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="9"><x-ui.empty-state icon="truck" :title="$isDrivers ? 'No drivers yet' : 'No guides yet'" description="Add the people who run your trips so they can be assigned." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    <x-ui.slide-over wire:model="showForm" :title="($editingId ? 'Edit ' : 'Add ').($isDrivers ? 'driver' : 'guide')">
        <form wire:submit="save" id="resource-form" class="grid gap-3">
            <x-ui.input label="Name" wire:model="form.name" id="res-name" />
            <x-ui.input label="Phone" wire:model="form.phone" id="res-phone" />
            @if ($isDrivers)
                <x-ui.input label="Vehicle" wire:model="form.vehicle" id="res-vehicle" placeholder="Toyota Land Cruiser" />
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input label="Registration" wire:model="form.vehicle_registration" id="res-reg" />
                    <x-ui.input label="Licence number" wire:model="form.license_number" id="res-licence" />
                </div>
            @else
                <x-ui.input label="Languages" wire:model="form.languages" id="res-languages" hint="Separate with commas." />
                <x-ui.input label="Specialization" wire:model="form.specialization" id="res-spec" placeholder="Birding, Big Five…" />
            @endif
            <x-ui.select label="Provider" wire:model="form.travel_provider_id" id="res-provider">
                <option value="">Independent</option>
                @foreach ($providers as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
            </x-ui.select>
            <x-ui.select label="Status" wire:model="form.status" id="res-form-status">
                @foreach (\App\Enums\Travel\ResourceStatus::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
            </x-ui.select>
            <div class="grid gap-1">
                <label for="res-notes" class="text-xs font-medium text-ink-muted">Notes</label>
                <textarea id="res-notes" wire:model="form.notes" rows="3" class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink shadow-xs focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none"></textarea>
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="resource-form">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.slide-over>
</div>
