<div class="grid gap-5">
    <x-ui.page-header
        eyebrow="Management"
        title="Unattributed signups"
        description="Properties with no salesperson: signups without a referral code, and properties released from a fired salesperson. Assign one only when you're sure who brought it in. Every assignment is logged with your name and reason."
    >
        <x-slot:actions>
            @if ($onboardings->count() > 1)
                <x-ui.button variant="secondary" wire:click="openAssignSelected">Assign selected ({{ count($selected) }})</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
    @error('selected')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror

    <x-ui.table-card :paginator="$onboardings">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="text-left text-ink-subtle uppercase">
                <tr>
                    <th class="w-8 text-left"><span class="sr-only">Select</span></th>
                    <th class="text-left">Property</th>
                    <th class="text-left">Contact</th>
                    <th class="text-left">Signed up</th>
                    <th class="text-left">Status</th>
                    <th class="text-left"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($onboardings as $onboarding)
                    <tr wire:key="un-{{ $onboarding->id }}">
                        <td><input type="checkbox" wire:model="selected" value="{{ $onboarding->id }}" aria-label="Select {{ $onboarding->property_name }}" class="size-4 rounded border-line-strong"></td>
                        <td>
                            <div class="grid leading-tight">
                                <span class="font-semibold text-ink">{{ $onboarding->property_name }}</span>
                                <span class="text-[13px] text-ink-subtle">{{ $onboarding->propertyTypeLabel() }}{{ $onboarding->location ? ' · '.$onboarding->location : '' }}</span>
                            </div>
                        </td>
                        <td class="text-ink-muted">
                            <div class="grid leading-tight"><span>{{ $onboarding->contact_name ?? '—' }}</span><span class="text-[13px] text-ink-subtle">{{ $onboarding->contact_phone }}</span></div>
                        </td>
                        <td class="text-ink-muted">{{ $onboarding->submitted_at?->format('j M Y') }}</td>
                        <td><x-ui.pill :tone="$onboarding->status->tone()">{{ $onboarding->status->label() }}</x-ui.pill></td>
                        <td class="text-right"><x-ui.button size="sm" variant="secondary" wire:click="openAssign({{ $onboarding->id }})">Assign</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-ui.empty-state icon="check-circle" title="Every signup has a salesperson" description="Signups that arrive without a referral code, and properties released from a fired salesperson, will wait here for you." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.table-card>

    <x-ui.slide-over wire:model="showAssign" title="Assign to a salesperson" :description="$assigning->count() === 1 ? $assigning->first()->property_name : $assigning->count().' properties'">
        <form id="assign-form" wire:submit="assign" class="grid gap-4">
            <x-ui.select label="Salesperson" wire:model="salespersonId" id="assign-salesperson">
                <option value="">Choose…</option>
                @foreach ($salespeople as $person)
                    <option value="{{ $person->id }}">{{ $person->name }}{{ $person->region ? ' · '.$person->region : '' }}</option>
                @endforeach
            </x-ui.select>
            <div class="grid gap-1.5">
                <label for="assign-reason" class="text-xs font-medium text-ink-muted">Reason</label>
                <textarea id="assign-reason" wire:model="reason" rows="4" placeholder="e.g. Provider confirmed Mary guided them; they signed up from the homepage instead of her link."
                    class="w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-[13px] text-ink placeholder:text-ink-subtle focus:border-brand focus:ring-3 focus:ring-brand-soft focus:outline-none"></textarea>
                @error('reason')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="assign-form">Assign credit</x-ui.button>
        </x-slot:footer>
    </x-ui.slide-over>
</div>
